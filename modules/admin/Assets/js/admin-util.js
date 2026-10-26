// modules/admin/Assets/js/admin-util.js — 后台管理页（?v=admin）· 通用工具函数

function escAdmin(s){
    if (s === null || s === undefined) return '';
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
function fmtSizeAdmin(size){
    size = parseInt(size, 10) || 0;
    if (size >= 1048576) return (size / 1048576).toFixed(2) + ' MB';
    if (size >= 1024) return (size / 1024).toFixed(1) + ' KB';
    return size + ' B';
}
