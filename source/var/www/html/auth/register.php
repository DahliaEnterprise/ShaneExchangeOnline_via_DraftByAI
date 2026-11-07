<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $email = filter_var(
        trim($_POST['email'] ?? ''),
        FILTER_VALIDATE_EMAIL
    );

    $password = $_POST['password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    if (!$email) {
        $message = 'Enter a valid email address.';

    } elseif (!is_string($password) || strlen($password) < 12) {
        $message = 'Password must be at least 12 characters.';

    } elseif (!is_string($confirm) || $password !== $confirm) {
        $message = 'Passwords do not match.';

    } else {

        // Check whether this email already exists
        $stmt = $pdo->prepare(
            'SELECT id, verified FROM users
             WHERE email = ? LIMIT 1'
        );

        $stmt->execute([$email]);
        $existingUser = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($existingUser && (bool)$existingUser['verified']) {

            $message = 'This email is already registered. Please log in.';

        } else {

            // Generate a new verification token
            $token = bin2hex(random_bytes(32));

            $tokenHash = hash('sha256', $token);

            $expires = (new DateTimeImmutable('+24 hours'))
                ->format('Y-m-d H:i:s');

            if ($existingUser) {

                // Existing account: resend verification
                // and invalidate the previous token

                $update = $pdo->prepare(
                    'UPDATE users
                     SET verification_token_hash = ?,
                         verification_expires = ?
                     WHERE id = ? AND verified = 0'
                );

                $update->execute([
                    $tokenHash,
                    $expires,
                    $existingUser['id']
                ]);

            } else {

                // New account
                $passwordHash = password_hash(
                    $password,
                    PASSWORD_ARGON2ID
                );

                $insert = $pdo->prepare(
                    'INSERT INTO users
                    (
                        email,
                        password_hash,
                        verified,
                        verification_token_hash,
                        verification_expires
                    )
                    VALUES (?, ?, 0, ?, ?)'
                );

                $insert->execute([
                    $email,
                    $passwordHash,
                    $tokenHash,
                    $expires
                ]);
            }

            // Build verification link
            $link = rtrim(APP_BASE_URL, '/')
                . '/auth/verify.php?token='
                . urlencode($token);

            $subject = 'Verify your Shasta Exchange account';

            $body = "Hello,

Use this link to verify your email address.
It expires in 24 hours:

$link

If you did not request this, ignore this email.";

            $headers = "From: " . MAIL_FROM . "\r\n";
            $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";

            // Send verification email
            if (mail($email, $subject, $body, $headers)) {

                $message = 'A verification email has been sent. Check your inbox.';

            } else {

                error_log(
                    'Verification email could not be sent for ' . $email
                );

                $message = 'Your account is saved, but the verification email could not be sent. Please try registering again later.';
            }
        }
    }
}
?>

<!doctype html>
<html lang="en">

<head>
<meta charset="utf-8">

<meta name="viewport"
      content="width=device-width, initial-scale=1">

<title>Sign up - Shasta Exchange</title>

<style>

body {
    font-family: Arial, sans-serif;
    background: #121826;
    color: white;

    display: grid;
    place-items: center;

    min-height: 100vh;
    margin: 0;
}

.card {
    background: #202b3d;
    padding: 28px;
    border-radius: 14px;

    width: min(390px, 88vw);
}

input, button {
    box-sizing: border-box;
    width: 100%;

    padding: 12px;
    margin: 8px 0;

    border-radius: 7px;
    border: 0;
}

button {
    background: #477de0;
    color: white;
    font-weight: bold;
    cursor: pointer;
}

a {
    color: #9fc2ff;
}

.msg {
    color: #ffd28a;
}

</style>
</head>

<body>

<main class="card">

    <h1>Create account</h1>

    <p class="msg">
        <?= htmlspecialchars(
            $message,
            ENT_QUOTES,
            'UTF-8'
        ) ?>
    </p>

    <form method="post">

        <label>Email</label>

        <input
            type="email"
            name="email"
            required
            autocomplete="email"
        >

        <label>Password (12+ characters)</label>

        <input
            type="password"
            name="password"
            required
            minlength="12"
            autocomplete="new-password"
        >

        <label>Confirm password</label>

        <input
            type="password"
            name="confirm_password"
            required
            minlength="12"
            autocomplete="new-password"
        >

        <button type="submit">
            Sign up
        </button>

    </form>

    <p>
        <a href="/auth/login.php">
            Already registered? Log in
        </a>
    </p>

</main>

</body>
</html>
