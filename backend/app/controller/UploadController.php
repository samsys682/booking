<?php
// =====================================================================
// booking 后端 · 文件上传控制器
//
//   POST /api/v1/upload   → multipart/form-data，字段名 file
//                          可选字段 kind = image | video（缺省按扩展名推断）
//                          返回 {url, name, size, ext, mime}
//
// 安全红线（对齐《开发规范》文件上传规范）：
//   1. 扩展名白名单（小写比对），拒绝 php/phtml/js/svg 等可执行/脚本类
//   2. 真实 MIME 校验（finfo getMime），与扩展名交叉核对
//   3. 图片额外走 getimagesize 二次确认（防改扩展名的伪图）
//   4. 大小上限（图片 5MB / 视频 20MB）
//   5. 落盘文件名随机生成，绝不复用用户上传的原始文件名（防路径穿越 / 覆盖）
//   6. 鉴权：全局 AuthMiddleware 保证任意已登录端（admin/member/tech）可上传；
//      不挂 RBAC（评价晒图走会员 token、服务图走管理员 token，无法用单一权限码覆盖）
// =====================================================================

namespace app\controller;

use app\service\AuditService;
use think\Request;
use think\Response;

class UploadController
{
    /** 允许的图片扩展名 */
    private const IMAGE_EXT = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'];

    /** 允许的视频扩展名 */
    private const VIDEO_EXT = ['mp4', 'webm'];

    /** 扩展名 → 期望 MIME 前缀（交叉核对，容忍 image/x-ms-bmp 等别名） */
    private const MIME_MAP = [
        'jpg'  => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png'  => ['image/png'],
        'gif'  => ['image/gif'],
        'webp' => ['image/webp'],
        'bmp'  => ['image/bmp', 'image/x-ms-bmp', 'image/x-bmp'],
        'mp4'  => ['video/mp4', 'application/mp4'],
        'webm' => ['video/webm'],
    ];

    private const IMAGE_MAX = 5 * 1024 * 1024;   // 5 MB
    private const VIDEO_MAX = 20 * 1024 * 1024;  // 20 MB

    /**
     * 上传文件
     * POST /api/v1/upload  (multipart, field: file)
     */
    public function upload(Request $request): Response
    {
        $file = $request->file('file');
        if (!$file) {
            return err(400, '未接收到上传文件（字段名应为 file）');
        }

        // 1) 上传是否成功（PHP 层错误：超 ini 限制 / 部分上传 / 临时目录不可写）
        if (!$file->isValid()) {
            return err(400, '文件上传失败（可能超过服务器允许的最大体积）');
        }

        // 2) 扩展名白名单
        $ext = strtolower($file->getOriginalExtension());
        $isImage = in_array($ext, self::IMAGE_EXT, true);
        $isVideo = in_array($ext, self::VIDEO_EXT, true);
        if (!$isImage && !$isVideo) {
            return err(400, '不支持的文件类型，仅允许图片（' . implode('/', self::IMAGE_EXT) . '）或视频（' . implode('/', self::VIDEO_EXT) . '）');
        }

        // 3) 大小上限
        $size = (int)$file->getSize();
        $max = $isImage ? self::IMAGE_MAX : self::VIDEO_MAX;
        if ($size <= 0) {
            return err(400, '文件为空');
        }
        if ($size > $max) {
            return err(400, '文件过大，上限 ' . (int)($max / 1024 / 1024) . 'MB');
        }

        // 4) 真实 MIME 校验（读文件头，不信浏览器声明的 type）
        $mime = (string)$file->getMime();
        $expect = self::MIME_MAP[$ext] ?? [];
        if ($expect && !in_array($mime, $expect, true)) {
            return err(400, '文件内容与扩展名不符（检测到 ' . $mime . '）');
        }

        // 5) 图片二次确认：必须是可解析的图像
        if ($isImage) {
            $info = @getimagesize($file->getPathname());
            if ($info === false || empty($info[0]) || empty($info[1])) {
                return err(400, '图片文件损坏或格式不被支持');
            }
        }

        // 6) 随机文件名落盘（绝不用原始文件名）
        $sub = date('Y/m');
        $dir = public_path() . 'uploads' . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $sub);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return err(500, '上传目录创建失败，请检查 public/uploads 写权限');
        }
        $name = date('YmdHis') . '_' . bin2hex(random_bytes(8)) . '.' . $ext;

        try {
            $file->move($dir, $name);
        } catch (\Throwable $e) {
            return err(500, '文件保存失败：' . $e->getMessage());
        }

        $url = '/uploads/' . $sub . '/' . $name;

        // 审计（不阻塞主流程）
        try {
            (new AuditService())->log(
                'upload.file',
                'upload',
                $url . '（' . $size . 'B / ' . $mime . '）',
                (int)$request->adminId
            );
        } catch (\Throwable $e) {
            // 审计失败不影响上传结果
        }

        return ok([
            'url'  => $url,
            'name' => $name,
            'size' => $size,
            'ext'  => $ext,
            'mime' => $mime,
            'kind' => $isImage ? 'image' : 'video',
        ], '上传成功');
    }
}
