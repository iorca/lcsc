// modules/inventory/Assets/js/parts-attachment.js — 物料详情抽屉 · 图片与 PDF 手册

function attPick(partId, kind) {
    var pid = parseInt(partId, 10) || 0;
    if (!pid) { LCSC.toast('物料ID无效', 'error'); return; }

    var isPdf = kind === 'pdf';
    var input = document.createElement('input');
    input.type = 'file';
    input.hidden = true;
    input.accept = isPdf
        ? 'application/pdf,.pdf'
        : 'image/jpeg,image/png,image/gif,image/webp,.jpg,.jpeg,.png,.gif,.webp';
    document.body.appendChild(input);

    input.addEventListener('change', function () {
        var file = input.files && input.files[0];
        input.remove();
        if (file) attUpload(pid, kind, file);
    });

    input.click();
}

/* 上传单个附件 */
function attUpload(partId, kind, file) {
    var pid = parseInt(partId, 10) || 0;
    if (!pid) { LCSC.toast('物料ID无效', 'error'); return; }

    var fd = new FormData();
    fd.append('action', 'asset.upload_attachment');
    fd.append('part_id', pid);
    fd.append('kind', kind);
    fd.append('file', file, file.name);

    var label = kind === 'pdf' ? '手册' : '图片';
    LCSC.post('api.php', fd, function (data, msg) {
        LCSC.toast(msg || (label + '上传成功'), 'success');
        attReload(pid);
    }, function (msg) {
        LCSC.toast(msg || (label + '上传失败'), 'error');
    });
}

/* 从立创商城下载该物料的规格书 PDF，存本地并自动绑定 */
function attFetchDatasheet(partId) {
    var pid = parseInt(partId, 10) || 0;
    if (!pid) { LCSC.toast('物料ID无效', 'error'); return; }

    LCSC.toast('正在从立创下载规格书…', 'info');

    LCSC.post('api.php', {
        action: 'asset.datasheet_download',
        part_id: pid
    }, function (data, msg) {
        var st = data || {};
        if ((st.downloaded || 0) > 0) {
            LCSC.toast(msg || '规格书已下载并绑定', 'success');
        } else if ((st.failed || 0) > 0) {
            var detail = (st.details || [])[0];
            LCSC.toast('下载失败：' + ((detail && detail.msg) || '未知原因'), 'error');
        } else {
            LCSC.toast('该物料没有可下载的规格书（立创未提供）', 'info');
        }
        attReload(pid);
    }, function (msg) {
        LCSC.toast(msg || '规格书下载失败', 'error');
    });
}

/* 删除附件（带二次确认） */
function attDelete(partId, attId, btn) {
    var pid = parseInt(partId, 10) || 0;
    var aid = parseInt(attId, 10) || 0;
    if (!pid || !aid) { LCSC.toast('参数无效', 'error'); return; }

    AdminUIConfirm({
        title: '删除附件',
        message: '确定删除该附件？物理文件会一并删除，不可恢复。',
        okText: '删除',
        danger: true,
        onOk: function () {
            if (btn) btn.disabled = true;
            LCSC.post('api.php', { action: 'asset.delete_attachment', id: aid }, function (data, msg) {
                LCSC.toast(msg || '删除成功', 'success');
                attReload(pid);
            }, function (msg) {
                if (btn) btn.disabled = false;
                LCSC.toast(msg || '删除失败', 'error');
            });
        }
    });
}

/* 切换大图 */
function attSwitch(partId, filepath, btn) {
    var main = document.getElementById('attMainImg_' + partId);
    if (main && filepath) main.src = attImgSrc(filepath, 800);
    if (!btn || !btn.parentNode) return;
    var siblings = btn.parentNode.querySelectorAll('.att-thumb');
    for (var i = 0; i < siblings.length; i++) siblings[i].classList.remove('on');
    btn.classList.add('on');
}

/* 图片 src 归一：本地相对路径走缩略图接口，http(s) 外链原样返回 */
function attImgSrc(filepath, size) {
    if (!filepath) return '';
    if (/^https?:\/\//i.test(filepath)) return filepath;
    var s = size || 160;
    return 'app/api/router.php?action=asset.thumb&size=' + s + '&path=' + encodeURIComponent(filepath);
}

/* 图片预览：页内灯箱（遮罩 + 原图），不跳出浏览器 */
function attPreview(filepath) {
    if (!filepath) { LCSC.toast('文件路径无效', 'error'); return; }
    var box = document.getElementById('attLightbox');
    if (!box) {
        box = document.createElement('div');
        box.id = 'attLightbox';
        box.className = 'att-lightbox';
        box.setAttribute('role', 'dialog');
        box.setAttribute('aria-modal', 'true');
        box.innerHTML = '<button type="button" class="att-lb-close" title="关闭（Esc）" aria-label="关闭">×</button>'
            + '<img class="att-lb-img" alt="预览">';
        document.body.appendChild(box);
        // 事件委托挂在容器上，只绑一次（灯箱是单例复用的）
        box.addEventListener('click', function(e) {
            if (e.target === box || e.target.closest('.att-lb-close')) { attPreviewClose(); }
        });
    }
    var img = box.querySelector('.att-lb-img');
    img.src = filepath;
    img.alt = filepath;
    box.classList.add('is-open');
    document.body.classList.add('att-lb-locked');
    box.querySelector('.att-lb-close').focus();
}

/* 关闭图片灯箱 */
function attPreviewClose() {
    var box = document.getElementById('attLightbox');
    if (!box) return;
    box.classList.remove('is-open');
    document.body.classList.remove('att-lb-locked');
    box.querySelector('.att-lb-img').removeAttribute('src');
}

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') attPreviewClose();
}, true);

/* 上传 / 删除后重开抽屉刷新展示 */
function attReload(partId) {
    var pid = parseInt(partId, 10) || 0;
    if (pid && typeof openDrawer === 'function') openDrawer(pid);
}
