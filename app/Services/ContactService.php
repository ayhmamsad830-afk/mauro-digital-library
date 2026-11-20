<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Models\Setting;
use App\Services\Mail\Mailer;
use App\Support\Paginator;

/**
 * Contact requests (spec: contact page). Every valid request is stored first;
 * the administrator notification is a best-effort extra whose real outcome is
 * kept in notify_status:
 *   not_configured — no e-mail delivery or no contact address set: stored only;
 *   sent / failed  — what the SMTP server answered.
 * Administrators read the requests in /admin/messages either way.
 */
final class ContactService
{
    public const LIMITS = ['name' => [2, 80], 'email' => [3, 190], 'subject' => [3, 150], 'message' => [10, 5000]];
    public const STATUSES = ['new', 'read', 'archived'];

    /** @return array<string,string> field => translation key */
    public static function validate(array $in): array
    {
        $errors = [];
        foreach (self::LIMITS as $field => [$min, $max]) {
            $len = mb_strlen(trim((string) ($in[$field] ?? '')));
            if ($len < $min || $len > $max) {
                $errors[$field] = "contact.invalid_$field";
            }
        }
        $email = trim((string) ($in['email'] ?? ''));
        if (!isset($errors['email']) && (filter_var($email, FILTER_VALIDATE_EMAIL) === false || preg_match('/[\r\n]/', $email))) {
            $errors['email'] = 'contact.invalid_email';
        }
        return $errors;
    }

    /**
     * @param array{name?:string,email?:string,subject?:string,message?:string} $in
     * @return array{ok:bool, errors:array<string,string>, reason:?string, delivery:?string}
     *   reason: throttled | invalid | null; delivery: not_configured | sent | failed
     */
    public static function submit(array $in, string $ip, ?int $userId, string $locale): array
    {
        // Invalid input stores nothing, so only valid submissions count against the limit.
        $errors = self::validate($in);
        if ($errors !== []) {
            return ['ok' => false, 'errors' => $errors, 'reason' => 'invalid', 'delivery' => null];
        }
        if (!RateLimiter::attempt('contact', 'ip:' . $ip)) {
            return ['ok' => false, 'errors' => [], 'reason' => 'throttled', 'delivery' => null];
        }
        $v = array_map(static fn ($s) => trim((string) $s), $in);
        $now = gmdate('Y-m-d H:i:s');
        Database::query(
            "INSERT INTO contact_messages (name, email, subject, message, locale, user_id, ip_hash, status, notify_status, created_at)
             VALUES (:n, :e, :s, :m, :l, :u, :i, 'new', 'not_configured', :c)",
            ['n' => $v['name'], 'e' => mb_strtolower($v['email']), 's' => $v['subject'], 'm' => $v['message'], 'l' => $locale === 'en' ? 'en' : 'ar',
             'u' => $userId, 'i' => hash('sha256', $ip . '|' . app_key()), 'c' => $now]
        );
        $id = Database::lastInsertId();

        $to = (string) (Setting::get('site.contact_email') ?? '');
        $delivery = 'not_configured';
        if (Mailer::canDeliver() && filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $status = Mailer::send('contact_notification', $to, __('contact.mail_subject', ['subject' => $v['subject']]),
                __('contact.mail_text', ['name' => $v['name'], 'email' => $v['email'], 'subject' => $v['subject'], 'message' => $v['message'], 'brand' => brand_name()]));
            $delivery = $status === 'sent' ? 'sent' : 'failed';
            Database::query('UPDATE contact_messages SET notify_status = :s WHERE id = :id', ['s' => $delivery, 'id' => $id]);
        }
        return ['ok' => true, 'errors' => [], 'reason' => null, 'delivery' => $delivery];
    }

    public static function paginate(?string $status, int $page, int $perPage = 25): Paginator
    {
        $where = in_array($status, self::STATUSES, true) ? 'WHERE status = :st' : "WHERE status <> 'archived'";
        $params = in_array($status, self::STATUSES, true) ? ['st' => $status] : [];
        $total = (int) Database::scalar("SELECT COUNT(*) FROM contact_messages $where", $params);
        $page = max(1, min($page, max(1, (int) ceil($total / $perPage))));
        $items = Database::all(
            "SELECT id, name, email, subject, message, locale, status, notify_status, created_at, read_at
               FROM contact_messages $where ORDER BY created_at DESC, id DESC LIMIT :lim OFFSET :off",
            $params + ['lim' => $perPage, 'off' => ($page - 1) * $perPage]
        );
        return new Paginator($items, $total, $page, $perPage);
    }

    public static function unreadCount(): int
    {
        return (int) Database::scalar("SELECT COUNT(*) FROM contact_messages WHERE status = 'new'");
    }

    public static function setStatus(int $id, string $status, int $adminId, ?string $ip): void
    {
        if (!in_array($status, self::STATUSES, true)) {
            throw new \App\Core\HttpException(422, __('validation.invalid'));
        }
        $row = Database::first('SELECT id, subject FROM contact_messages WHERE id = :id', ['id' => $id]);
        if (!$row) {
            throw \App\Core\HttpException::notFound();
        }
        Database::query('UPDATE contact_messages SET status = :s, read_at = COALESCE(read_at, :t), read_by = COALESCE(read_by, :a) WHERE id = :id',
            ['s' => $status, 't' => gmdate('Y-m-d H:i:s'), 'a' => $adminId, 'id' => $id]);
        \App\Models\ActivityLog::record($adminId, 'contact.' . $status, 'contact_message', $id, (string) $row['subject'], $ip);
    }
}
