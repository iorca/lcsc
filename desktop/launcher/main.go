// LCSC 桌面版启动器（WinNMP 思路）
//
// 启动顺序：MariaDB -> php-cgi(FastCGI 9000) -> nginx(8080) -> 打开浏览器
// 退出时反向回收子进程。数据目录：
//   - 存在 portable.flag  -> 同目录 data/（便携版）
//   - 否则                -> %LOCALAPPDATA%\LCSC（安装版）
//
// 期望目录结构（由 package.ps1 / action 组装）：
//   launcher.exe
//   runtime/{nginx,php,mariadb}/...
//   www/                  <- web-dist 内容（index.php 等）
//   conf/{nginx.conf,php.ini}
//   [portable.flag]       <- 便携版标记
package main

import (
	"context"
	"fmt"
	"io"
	"log"
	"net"
	"os"
	"os/exec"
	"os/signal"
	"path/filepath"
	"runtime"
	"strings"
	"time"
)

const (
	httpPort    = "8080"
	fastcgiAddr = "127.0.0.1:9000"
	mysqlPort    = "3306"
	dbName      = "lcsc"
)

func main() {
	exe, err := os.Executable()
	if err != nil {
		log.Fatalf("获取自身路径失败: %v", err)
	}
	baseDir := filepath.Dir(exe)
	runtimeDir := filepath.Join(baseDir, "runtime")
	wwwDir := filepath.Join(baseDir, "www")
	if !fileExists(wwwDir) {
		// 兼容打包时把 PHP 应用根命名为 app/ 的情况（而非 www/）
		if alt := filepath.Join(baseDir, "app"); fileExists(alt) {
			wwwDir = alt
		}
	}
	confDir := filepath.Join(baseDir, "conf")

	portable := fileExists(filepath.Join(baseDir, "portable.flag"))
	var dataDir string
	if portable {
		dataDir = filepath.Join(baseDir, "data")
	} else {
		localApp := os.Getenv("LOCALAPPDATA")
		if localApp == "" {
			localApp = baseDir
		}
		dataDir = filepath.Join(localApp, "LCSC")
	}
	logsDir := filepath.Join(dataDir, "logs")
	mustMkdir(dataDir, logsDir)

	mariadbData := filepath.Join(dataDir, "mysql")
	mariadbBin := filepath.Join(runtimeDir, "mariadb", "bin", "mariadbd.exe")
	mysqlClient := filepath.Join(runtimeDir, "mariadb", "bin", "mysql.exe")
	phpCgi := filepath.Join(runtimeDir, "php", "php-cgi.exe")
	phpIni := filepath.Join(confDir, "php.ini")
	nginxBin := filepath.Join(runtimeDir, "nginx", "nginx.exe")
	nginxConfTmpl := filepath.Join(confDir, "nginx.conf")

	if !fileExists(wwwDir) {
		log.Fatalf("找不到 www 目录: %s（请先放入 web-dist 内容）", wwwDir)
	}

	// 登录密码开关（桌面版默认免登录，适合纯本机单人直接进系统）
	// 在桌面版根目录放置 require_login.flag 即可恢复「需要输入账号密码」
	if fileExists(filepath.Join(baseDir, "require_login.flag")) {
		os.Setenv("LCSC_LOGIN_ENABLED", "true")
		log.Println("检测到 require_login.flag：已启用登录密码")
	} else {
		os.Setenv("LCSC_LOGIN_ENABLED", "false")
		log.Println("登录密码已关闭（免登录直接进入）；放置 require_login.flag 可恢复密码登录")
	}

	// 1. 初始化 MariaDB 数据目录（首次）
	//
	// ⚠️ 关键：MariaDB 11.x **不支持** MySQL 的 `--initialize-insecure`
	// （mariadbd 会报 "unknown option '--initialize-insecure'" 并 Aborting）。
	// MariaDB 的正确初始化姿势是用官方 bootstrap 工具 mysql_install_db
	// / mariadb-install-db，且 --datadir 必须给**绝对路径**。
	if !fileExists(filepath.Join(mariadbData, "mysql")) {
		log.Println("初始化 MariaDB 数据目录...")
		if err := os.MkdirAll(mariadbData, 0755); err != nil {
			log.Fatalf("创建 datadir 失败: %v", err)
		}
		installDb := filepath.Join(runtimeDir, "mariadb", "bin", "mariadb-install-db.exe")
		if !fileExists(installDb) {
			installDb = filepath.Join(runtimeDir, "mariadb", "bin", "mysql_install_db.exe")
		}
		if !fileExists(installDb) {
			log.Fatalf("找不到 MariaDB 初始化工具: %s", installDb)
		}
		initCmd := exec.Command(installDb,
			"--datadir="+mariadbData,
			"--port="+mysqlPort,
		)
		initCmd.Stdout = os.Stdout
		initCmd.Stderr = os.Stderr
		if err := initCmd.Run(); err != nil {
			log.Fatalf("MariaDB 初始化失败: %v", err)
		}
		log.Println("MariaDB 初始化完成")
	}

	// 2. 生成 nginx.conf（把 root / 运行时路径全部替换成绝对路径）
	nginxConf := filepath.Join(logsDir, "nginx.conf")
	genNginxConf(nginxConfTmpl, nginxConf, wwwDir, runtimeDir, logsDir)

	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()

	// 3. 起 MariaDB
	mariadbCmd := startProcess(ctx, "mariadb", mariadbBin, []string{
		"--datadir=" + mariadbData,
		"--port=" + mysqlPort,
		"--log-error=" + filepath.Join(logsDir, "mariadb.log"),
	}, runtimeDir, logsDir)
	if err := waitForPort("127.0.0.1:"+mysqlPort, 30*time.Second); err != nil {
		log.Fatalf("MariaDB 启动超时: %v", err)
	}
	log.Println("MariaDB 就绪")

	// 2) 建业务库（若不存在）
	// mysql_install_db 只建系统库（mysql/performance_schema/sys），业务库要自己建。
	if err := exec.Command(mysqlClient,
		"-u", "root", "-h", "127.0.0.1", "--port="+mysqlPort,
		"-e", "CREATE DATABASE IF NOT EXISTS `"+dbName+"` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;",
	).Run(); err != nil {
		log.Printf("创建数据库 %s 失败（不阻断启动，可由安装向导处理）: %v", dbName, err)
	} else {
		log.Printf("数据库 %s 就绪", dbName)
	}

	// 4. 起 php-cgi（先由模板生成 extension_dir 为绝对路径的 php.ini）
	phpIniGen := filepath.Join(logsDir, "php.ini")
	genPhpIni(phpIni, phpIniGen, filepath.Join(runtimeDir, "php"))
	phpCmd := startProcess(ctx, "php-cgi", phpCgi, []string{
		"-b", fastcgiAddr,
		"-c", phpIniGen,
	}, runtimeDir, logsDir)
	if err := waitForPort(fastcgiAddr, 15*time.Second); err != nil {
		log.Fatalf("php-cgi 启动超时: %v", err)
	}
	log.Println("php-cgi 就绪")

	// 5. 起 nginx
	nginxCmd := startProcess(ctx, "nginx", nginxBin, []string{
		"-p", filepath.Join(runtimeDir, "nginx"),
		"-c", nginxConf,
	}, runtimeDir, logsDir)
	if err := waitForPort("127.0.0.1:"+httpPort, 15*time.Second); err != nil {
		log.Fatalf("nginx 启动超时: %v", err)
	}
	log.Printf("nginx 就绪，服务地址 http://127.0.0.1:%s", httpPort)

	openBrowser("http://127.0.0.1:" + httpPort)

	// 6. 等待退出信号
	sig := make(chan os.Signal, 1)
	signal.Notify(sig, os.Interrupt)
	<-sig
	log.Println("收到退出信号，正在关闭服务...")
	cancel()
	shutdownNginx(nginxBin, nginxCmd)
	shutdownSimple(phpCmd)
	shutdownMariaDB(mysqlClient, mariadbCmd)
	log.Println("已关闭，再见。")
}

// ---------- helpers ----------

func fileExists(p string) bool {
	_, err := os.Stat(p)
	return err == nil
}

func mustMkdir(dirs ...string) {
	for _, d := range dirs {
		if err := os.MkdirAll(d, 0755); err != nil {
			log.Fatalf("创建目录失败 %s: %v", d, err)
		}
	}
}

// genNginxConf 由模板生成实际使用的 nginx.conf。
// ⚠️ nginx 的 include 相对路径是相对**配置文件所在目录**（conf_prefix）解析的，
// 不是 -p 指定的 prefix，所以模板里凡是要引用别的目录的文件，一律写绝对路径。
// 运行时用 {{RUNTIME}} / {{LOGS}} 占位符注入绝对路径，{{ROOT}} 注入 web 根。
func genNginxConf(tmpl, out, www, runtimeDir, logsDir string) {
	b, err := os.ReadFile(tmpl)
	if err != nil {
		log.Fatalf("读取 nginx.conf 模板失败: %v", err)
	}
	s := string(b)
	s = strings.ReplaceAll(s, "{{ROOT}}", filepath.ToSlash(www))
	s = strings.ReplaceAll(s, "{{RUNTIME}}", filepath.ToSlash(runtimeDir))
	s = strings.ReplaceAll(s, "{{LOGS}}", filepath.ToSlash(logsDir))
	if err := os.WriteFile(out, []byte(s), 0644); err != nil {
		log.Fatalf("写 nginx.conf 失败: %v", err)
	}
}

// genPhpIni 由模板生成一份 extension_dir 为绝对路径的 php.ini。
// 因为 php-cgi 通过 -c 指向 conf/php.ini（不在 php 目录下），相对 extension_dir
// 会被解析到 conf/ext（不存在）；改为绝对路径确保扩展正确加载。
func genPhpIni(tmpl, out, phpDir string) {
	b, err := os.ReadFile(tmpl)
	if err != nil {
		log.Fatalf("读取 php.ini 模板失败: %v", err)
	}
	extDir := "extension_dir = \"" + filepath.ToSlash(filepath.Join(phpDir, "ext")) + "\""
	lines := strings.Split(string(b), "\n")
	for i, ln := range lines {
		if strings.HasPrefix(strings.TrimSpace(ln), "extension_dir") {
			lines[i] = extDir
		}
	}
	if err := os.WriteFile(out, []byte(strings.Join(lines, "\n")), 0644); err != nil {
		log.Fatalf("写 php.ini 失败: %v", err)
	}
}

func startProcess(ctx context.Context, name, path string, args []string, dir, logsDir string) *exec.Cmd {
	cmd := exec.CommandContext(ctx, path, args...)
	cmd.Dir = dir
	logFile := filepath.Join(logsDir, name+".log")
	w := io.Writer(os.Stdout)
	if lf, err := os.OpenFile(logFile, os.O_CREATE|os.O_WRONLY|os.O_APPEND, 0644); err == nil {
		w = io.MultiWriter(os.Stdout, lf)
	}
	cmd.Stdout = w
	cmd.Stderr = w
	if err := cmd.Start(); err != nil {
		log.Fatalf("启动 %s 失败: %v", name, err)
	}
	log.Printf("%s 已启动 (pid=%d)", name, cmd.Process.Pid)
	return cmd
}

func waitForPort(addr string, timeout time.Duration) error {
	deadline := time.Now().Add(timeout)
	for time.Now().Before(deadline) {
		c, err := net.DialTimeout("tcp", addr, 500*time.Millisecond)
		if err == nil {
			c.Close()
			return nil
		}
		time.Sleep(300 * time.Millisecond)
	}
	return fmt.Errorf("端口 %s 在 %s 内未就绪", addr, timeout)
}

func shutdownNginx(bin string, cmd *exec.Cmd) {
	if cmd.Process == nil {
		return
	}
	// 优雅停止
	exec.Command(bin, "-s", "stop").Run()
	time.Sleep(1500 * time.Millisecond)
	taskkill(cmd.Process.Pid)
}

func shutdownSimple(cmd *exec.Cmd) {
	if cmd.Process != nil {
		taskkill(cmd.Process.Pid)
	}
}

func shutdownMariaDB(mysqlClient string, cmd *exec.Cmd) {
	if cmd.Process == nil {
		return
	}
	exec.Command(mysqlClient, "-u", "root", "-h", "127.0.0.1", "--port="+mysqlPort, "shutdown").Run()
	time.Sleep(2000 * time.Millisecond)
	taskkill(cmd.Process.Pid)
}

func taskkill(pid int) {
	if pid <= 0 {
		return
	}
	exec.Command("taskkill", "/PID", fmt.Sprint(pid), "/T", "/F").Run()
}

func openBrowser(url string) {
	var cmd *exec.Cmd
	switch runtime.GOOS {
	case "windows":
		cmd = exec.Command("cmd", "/c", "start", "", url)
	case "darwin":
		cmd = exec.Command("open", url)
	default:
		cmd = exec.Command("xdg-open", url)
	}
	_ = cmd.Start()
}
