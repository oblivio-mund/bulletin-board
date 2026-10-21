<?php
declare(strict_types=1);
$user = require_auth();
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verify_csrf($_POST['csrf_token'] ?? null)) { http_response_code(400); exit('Некорректный запрос.'); }
$adId=(int)($_POST['ad_id'] ?? 0);
if ($adId <= 0) { flash('error','Некорректный идентификатор объявления.'); header('Location: '.route_url('cabinet')); exit; }
try {
    $stmt=db()->prepare("UPDATE ads SET status='deleted', moderated_by=:author, moderated_at=CURRENT_TIMESTAMP, moderation_comment='Удалено автором' WHERE ad_id=:id AND author_id=:author AND status <> 'deleted'");
    $stmt->execute(['id'=>$adId,'author'=>$user['user_id']]);
    flash($stmt->rowCount() ? 'success' : 'error', $stmt->rowCount() ? 'Объявление удалено.' : 'Объявление не найдено или уже удалено.');
} catch (PDOException $e) { flash('error','Не удалось удалить объявление.'); }
header('Location: '.route_url('cabinet')); exit;
?>