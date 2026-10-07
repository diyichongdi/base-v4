<?php
/**
 * novel.php — /api/novel/* 接口处理器
 *
 * 职责：小说章节内容的增删查改（聊天窗口支持"小说模式"逐章推送）。
 * 当前为最小可用实现 — 所有数据落盘到 KV store 'novels'。
 *
 * 前端契约（见 js/chat.js 中的 novel 模式调用）：
 *   GET  /api/novel/:id            — 取整部小说元数据
 *   GET  /api/novel/:id/chapters   — 取章节列表
 *   GET  /api/novel/:book/ch/:idx   — 取单章内容
 *   POST /api/novel                 — 创建（需登录）data={id,title,author,chapters:[]}
 *   POST /api/novel/:id/chapters    — 添加章节data={title,content}
 */
declare(strict_types=1);

function novel_route(array $segs, string $method): void {
    $user = chat_require_user();

    if ($method === 'GET') {
        $id = (string)($segs[1] ?? '');
        if ($id === '') {
            ok_res(['list' => kv_get_all('novels')]);
            return;
        }
        $novel = kv_get('novels', $id);
        if (!$novel) err_res(apiL('err_novel_not_found'), 404);
        ok_res(['data' => $novel]);
        return;
    }

    if ($method === 'POST') {
        $body = req_json();
        $data = $body['data'] ?? null;
        if (!is_array($data)) err_res(apiL('err_missing_data'));
        $newId = (string)($data['id'] ?? chat_uid());
        $data['id'] = $newId;
        $data['authorId'] = $data['authorId'] ?? $user['id'];
        $data['createdAt'] = $data['createdAt'] ?? chat_now();
        kv_put('novels', $newId, $data);
        ok_res(['id' => $newId]);
        return;
    }

    err_res(apiL('err_no_method'), 405);
}
