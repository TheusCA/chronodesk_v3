<?php
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../services/PortalService.php';

function portal_username(): string {
    return sanitize_input(
        $_SESSION['admin_username']
            ?? $_SESSION['username']
            ?? $_SESSION['ci_username']
            ?? 'anonymous',
        100
    );
}

function portal_list_response(string $resource, array $roles = []): void {
    require_get_method();
    require_portal_auth($roles);
    $service = new PortalService();
    try {
        json_response([
            'sucesso' => true,
            'items' => $service->list($resource),
        ]);
    } catch (PortalStorageException $e) {
        json_response([
            'sucesso' => false,
            'mensagem' => $e->getMessage(),
        ], 503);
    }
}
