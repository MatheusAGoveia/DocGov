<?php

final class ProfileAvatarService
{
    public static function pathForUser(PDO $pdo, int $userId): ?string
    {
        if ($userId <= 0) {
            return null;
        }

        $stmt = $pdo->prepare('SELECT avatar FROM users WHERE id = :id');
        $stmt->execute([':id' => $userId]);
        $path = trim((string) $stmt->fetchColumn());

        // Fotos enviadas pelo próprio sistema ficam nesta pasta e pertencem ao usuário.
        if (!preg_match('#^uploads/avatars/user_' . $userId . '_[a-zA-Z0-9_-]{8,64}\.(?:jpe?g|png|webp)$#i', $path)) {
            return null;
        }

        return is_file(__DIR__ . '/../' . $path) ? $path : null;
    }
}
