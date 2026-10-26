<?php
declare(strict_types=1);

namespace Modules\Inventory\Service;

/** 物料附件服务（图片 + PDF 手册） */
final class AttachmentService
{
    /** 图片：扩展名 => 允许的 MIME */
    private const IMAGE_MIME = [
        'jpg'  => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png'  => ['image/png'],
        'gif'  => ['image/gif'],
        'webp' => ['image/webp'],
    ];

    /** PDF 手册：扩展名 => 允许的 MIME */
    private const PDF_MIME = [
        'pdf' => ['application/pdf'],
    ];

    private const MAX_IMAGE_SIZE = 5 * 1024 * 1024;   // 5MB
    private const MAX_PDF_SIZE   = 20 * 1024 * 1024;  // 20MB：手册常带大量矢量图

    private \PDO $db;
    private int $uid;
    private int $dataUid;

    public function __construct(\PDO $db, int $uid, int $dataUid)
    {
        $this->db      = $db;
        $this->uid     = $uid;
        $this->dataUid = $dataUid;
    }

    /** 上传一个附件 */
    public function upload(int $partId, array $file, string $kind): array
    {
        if ($partId <= 0) throw new \PartException('缺少物料ID', 4);

        $allowed = $kind === 'pdf' ? self::PDF_MIME : self::IMAGE_MIME;
        $ext     = strtolower(pathinfo((string)($file['name'] ?? ''), PATHINFO_EXTENSION));
        $maxSize = $kind === 'pdf' ? self::MAX_PDF_SIZE : self::MAX_IMAGE_SIZE;
        $label   = $kind === 'pdf' ? 'PDF 手册' : '图片';

        if (!isset($allowed[$ext])) {
            throw new \PartException(
                $kind === 'pdf'
                    ? '手册仅支持 pdf 格式'
                    : '图片仅支持 jpg/png/gif/webp',
                4
            );
        }

        $err = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($err !== UPLOAD_ERR_OK) {
            throw new \PartException(
                $err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE
                    ? "{$label}超过服务器上传限制（upload_max_filesize=" . ini_get('upload_max_filesize') . '）'
                    : "{$label}上传失败",
                4
            );
        }

        $size = (int)($file['size'] ?? 0);
        if ($size <= 0)          throw new \PartException("{$label}为空文件", 4);
        if ($size > $maxSize)    throw new \PartException("{$label}不能超过" . round($maxSize / 1048576) . 'MB', 4);

        $tmp = (string)($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) throw new \PartException("{$label}上传失败", 4);
        if (!\isValidMime($tmp, $allowed[$ext]))     throw new \PartException("{$label}内容与扩展名不符，已拒绝", 4);

        // 物料存在性 + 数据归属：附件挂在别人物料上传会留下指向空物料的孤儿行
        $chk = $this->db->prepare("SELECT id FROM parts WHERE id=? AND user_id=? AND status!='deleted'");
        $chk->execute([$partId, $this->dataUid]);
        if (!$chk->fetch()) throw new \PartException('物料不存在或无权操作', 404);

        // 落盘文件名不带用户原始名：原始名可能含 `..`、中文、同名覆盖
        // （扩展名已经过白名单，故拼进去的 $ext 只可能是上面枚举出的那几个）
        $stored    = $partId . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        $relPath   = 'uploads/' . $stored;
        $absTarget = \dirname(__DIR__, 3) . '/' . $relPath;

        if (!\move_uploaded_file($tmp, $absTarget)) {
            throw new \PartException("{$label}保存失败，请检查 uploads 目录是否可写", 3);
        }

        $stmt = $this->db->prepare(
            "INSERT INTO attachments (user_id, part_id, target_type, target_id, filename, filepath, filetype, filesize)
             VALUES (?,?,'part',?,?,?,?,?)"
        );
        $stmt->execute([
            $this->dataUid, $partId, $partId,
            (string)$file['name'], $relPath, $kind, $size,
        ]);
        $id = (int)$this->db->lastInsertId();

        $this->syncMainColumn($partId, $kind, $relPath);

        // 图片即时生成两种尺寸的缩略图：把 GD 解码成本挪到上传这一瞬。

        if ($kind === 'image') {
            $thumb = new ThumbnailService();
            $thumb->resolve($relPath, ThumbnailService::SIZE_LIST);
            $thumb->resolve($relPath, ThumbnailService::SIZE_DETAIL);
        }

        \traceLog($this->uid, 'upload_attachment', 'part', $partId, ($kind === 'pdf' ? '手册' : '图片') . '：' . $file['name']);

        return [
            'id'        => $id,
            'filepath'  => $relPath,
            'filename'  => (string)$file['name'],
            'filetype'  => $kind,
            'filesize'  => $size,
        ];
    }

    /** 删除一个附件，并按需清空主图 / 手册列 */
    public function delete(int $id): void
    {
        if ($id <= 0) throw new \PartException('参数无效', 4);

        $stmt = $this->db->prepare("SELECT id, part_id, filepath, filetype FROM attachments WHERE id=? AND user_id=?");
        $stmt->execute([$id, $this->dataUid]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row) throw new \PartException('附件不存在', 404);

        $relPath = (string)$row['filepath'];
        $full = \dirname(__DIR__, 3) . '/' . $relPath;
        if (\is_file($full)) @unlink($full);

        if ((string)$row['filetype'] === 'image') {
            (new ThumbnailService())->purge($relPath);
        }

        $this->db->prepare("DELETE FROM attachments WHERE id=?")->execute([$id]);

        $this->clearMainColumnIfMatches((int)$row['part_id'], (string)$row['filetype'], (string)$row['filepath']);

        \traceLog($this->uid, 'delete_attachment', 'part', (int)$row['part_id'], (string)$row['filepath']);
    }

    /** 取物料附件列表（兼容未回填 target_id 的历史行） */
    public function listForPart(int $partId): array
    {
        if ($partId <= 0) return [];

        $stmt = $this->db->prepare(
            "SELECT id, filename, filepath, filetype, filesize
             FROM attachments
             WHERE target_type='part' AND target_id=? AND user_id=?
             ORDER BY id ASC"
        );
        $stmt->execute([$partId, $this->dataUid]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        if (empty($rows)) {
            $fb = $this->db->prepare(
                "SELECT id, filename, filepath, filetype, filesize
                 FROM attachments WHERE part_id=? AND user_id=? ORDER BY id ASC"
            );
            $fb->execute([$partId, $this->dataUid]);
            $rows = $fb->fetchAll(\PDO::FETCH_ASSOC);
        }

        return $rows;
    }

    /** 上传后同步主列：首张图片 → `parts.image`，首个 PDF → `parts.datasheet` */
    private function syncMainColumn(int $partId, string $kind, string $relPath): void
    {
        $col = $kind === 'pdf' ? 'datasheet' : 'image';
        $this->db->prepare("UPDATE parts SET {$col}=? WHERE id=? AND user_id=? AND ({$col} IS NULL OR {$col}='')")
            ->execute([$relPath, $partId, $this->dataUid]);
    }

    /** 删除时：列值正好指向被删文件才清空（否则指向别的附件，清掉会误伤） */
    private function clearMainColumnIfMatches(int $partId, string $kind, string $relPath): void
    {
        $col = ($kind === 'pdf' || $kind === 'datasheet') ? 'datasheet' : 'image';
        $this->db->prepare("UPDATE parts SET {$col}='' WHERE id=? AND user_id=? AND {$col}=?")
            ->execute([$partId, $this->dataUid, $relPath]);
    }
}
