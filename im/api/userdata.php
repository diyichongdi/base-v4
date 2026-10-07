<?php
/**
 * userdata.php — /api/userdata/* 接口处理器
 *
 * 职责：用户个人数据空间的 CRUD（聊天窗口"工具箱/桌面"功能）。
 * 当前为最小可用实现 — 所有数据落盘到 KV store 'userdata'。
 *
 * 前端契约（见 js/chat.js 中的 userdata 模式调用）：
 *   GET  /api/userdata/:id   — 取单条记录
 *   GET  /api/userdata       — 取当前用户全部个人数据
 *   POST /api/userdata       — 新增/更新 data={id?,key,value}
 *   DELETE /api/userdata/:id — 删除
 */
declare(strict_types=1);

function userdata_route(array $segs, string $method): void {
    $user = chat_require_user();
    $id = isset($segs[1]) ? (string)$segs[1] : '';

    if ($method === 'GET') {
        if ($id === '') {
            $all = [];
            foreach (kv_by_index('userdata', 'ownerId', $user['id']) as $row) {
                $all[] = $row;
            }
            ok_res(['list' => $all]);
            return;
        }
        $row = kv_get('userdata', $id);
        if (!$row) err_res(apiL('err_no_record'), 404);
        if (($row['ownerId'] ?? '') !== $user['id']) err_res(apiL('err_no_access'), 403);
        ok_res(['data' => $row]);
        return;
    }

    if ($method === 'POST') {
        $body = req_json();
        $data = $body['data'] ?? null;
        if (!is_array($data)) err_res(apiL('err_missing_data'));
        $newId = (string)($data['id'] ?? chat_uid());
        $data['id'] = $newId;
        $data['ownerId'] = $user['id'];
        $data['updatedAt'] = chat_now();
        kv_put('userdata', $newId, $data);
        ok_res(['id' => $newId]);
        return;
    }

    if ($method === 'DELETE') {
        $row = kv_get('userdata', $id);
        if (!$row) err_res(apiL('err_no_record'), 404);
        if (($row['ownerId'] ?? '') !== $user['id']) err_res(apiL('err_no_access'), 403);
        kv_del('userdata', $id);
        ok_res([]);
        return;
    }

    err_res(apiL('err_no_method'), 405);
}
