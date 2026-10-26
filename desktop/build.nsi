; LCSC 桌面版安装包（NSIS）
; 由 package.ps1 调用：makensis build.nsi
; 假定当前目录存在 payload/ （含 runtime/ www/ conf/ launcher.exe）
!include "MUI2.nsh"

!define APPNAME "LCSC"
!define VERSION "1.2.0"

Name "${APPNAME} ${VERSION}"
OutFile "LCSC-Setup-${VERSION}.exe"
InstallDir "$LOCALAPPDATA\${APPNAME}"
RequestExecutionLevel user

!insertmacro MUI_PAGE_DIRECTORY
!insertmacro MUI_PAGE_INSTFILES
!insertmacro MUI_UNPAGE_CONFIRM
!insertmacro MUI_UNPAGE_INSTFILES
!insertmacro MUI_LANGUAGE "SimpChinese"

Section "Install"
  SetOutPath "$INSTDIR"
  File /r "payload\*"

  CreateDirectory "$SMPROGRAMS\${APPNAME}"
  CreateShortcut "$SMPROGRAMS\${APPNAME}\${APPNAME}.lnk" "$INSTDIR\launcher.exe"
  CreateShortcut "$SMPROGRAMS\${APPNAME}\卸载.lnk" "$INSTDIR\Uninstall.exe"
  WriteUninstaller "$INSTDIR\Uninstall.exe"
SectionEnd

Section "Uninstall"
  ; 默认保留业务数据（删除需用户手动清理 $LOCALAPPDATA\${APPNAME}\data）
  Delete "$SMPROGRAMS\${APPNAME}\${APPNAME}.lnk"
  Delete "$SMPROGRAMS\${APPNAME}\卸载.lnk"
  RMDir "$SMPROGRAMS\${APPNAME}"
  Delete "$INSTDIR\Uninstall.exe"
  RMDir /r "$INSTDIR"
SectionEnd
