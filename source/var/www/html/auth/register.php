<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

require_once '/var/www-private/shasta_exchange/vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Redirect logged-in users.
if (!empty($_SESSION['user_id'])) {
    header('Location: /voicemail.php');
    exit;
}

$error = '';
$success = '';

/**
 * Send account verification email.
 */
function send_app_email(
    string $recipient,
    string $subject,
    string $html,
    string $plainText = ''
): bool {

    $mail = new PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host = SMTP_HOST;
        $mail->SMTPAuth = true;
        $mail->Username = SMTP_USERNAME;
        $mail->Password = SMTP_PASSWORD;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = SMTP_PORT;

        $mail->setFrom(SMTP_USERNAME, 'Shasta Exchange');
        $mail->addAddress($recipient);

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $html;
        $mail->AltBody = $plainText ?: strip_tags($html);

        $mail->send();

        return true;

    } catch (Exception $e) {
        error_log('Email error: ' . $mail->ErrorInfo);
        return false;
    }
}

function send_verification_email(
    string $email,
    string $token
): bool {

    $url = rtrim(APP_BASE_URL, '/')
         . '/auth/verify.php?token='
         . urlencode($token);

    $safeUrl = htmlspecialchars(
        $url,
        ENT_QUOTES,
        'UTF-8'
    );

    $html = '
        <h2>Welcome to Shasta Exchange!</h2>

        <p>Thank you for creating an account.</p>

        <p>Please verify your email address by clicking below:</p>

        <p>
            <a href="' . $safeUrl . '">
                Verify Email Address
            </a>
        </p>

        <p>This verification link expires in 24 hours.</p>

        <p>Shasta Exchange</p>
    ';

    $plainText = "Welcome to Shasta Exchange!\n\n"
               . "Verify your email address:\n"
               . $url
               . "\n\nThis link expires in 24 hours.";

    return send_app_email(
        $email,
        'Verify your Shasta Exchange account',
        $html,
        $plainText
    );
}


// Process registration.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $email = strtolower(
        trim($_POST['email'] ?? '')
    );

    $password = $_POST['password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';


    // Validate email.
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {

        $error = 'Please enter a valid email address.';

    }

    // Validate password length.
    elseif (strlen($password) < 8) {

        $error = 'Password must be at least 8 characters.';

    }

    // Verify passwords match.
    elseif ($password !== $confirm) {

        $error = 'Passwords do not match.';

    }

    else {

        try {

            // Check whether the email already exists.
            $stmt = $pdo->prepare(
                'SELECT id, verified
                 FROM users
                 WHERE email = ?
                 LIMIT 1'
            );

            $stmt->execute([$email]);

            $existing = $stmt->fetch(PDO::FETCH_ASSOC);


            if ($existing && (int)$existing['verified'] === 1) {

                $error = 'This email address is already registered.';

            }

            else {

                // Generate a secure verification token.
                $token = bin2hex(random_bytes(32));

                // Store only the token hash.
                $tokenHash = hash('sha256', $token);

                // Verification expires after 24 hours.
                $expires = date(
                    'Y-m-d H:i:s',
                    time() + 86400
                );


                if ($existing) {

                    // Existing unverified account.
                    // Update its verification token.

                    $stmt = $pdo->prepare(
                        'UPDATE users
                         SET verification_token_hash = ?,
                             verification_expires = ?
                         WHERE id = ?'
                    );

                    $stmt->execute([
                        $tokenHash,
                        $expires,
                        $existing['id']
                    ]);

                }

                else {

                    // Create a new account.
                    $passwordHash = password_hash(
                        $password,
                        PASSWORD_DEFAULT
                    );

                    $stmt = $pdo->prepare(
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

                    $stmt->execute([
                        $email,
                        $passwordHash,
                        $tokenHash,
                        $expires
                    ]);

                }


                // Send verification email.
                if (send_verification_email($email, $token)) {

                    $success = 'Your account has been created! '
                             . 'Please check your email to verify your account.';

                }

                else {

                    $error = 'Your account was created, but the '
                           . 'verification email could not be sent. '
                           . 'Please try again later.';

                }

            }

        }

        catch (PDOException $e) {

            error_log(
                'Registration database error: '
                . $e->getMessage()
            );

            $error = 'Registration failed. Please try again.';

        }

        catch (Throwable $e) {

            error_log(
                'Registration error: '
                . $e->getMessage()
            );

            $error = 'An unexpected error occurred. Please try again.';

        }

    }

}

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport"
          content="width=device-width, initial-scale=1">

    <title>Register - Shasta Exchange</title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet">

</head>

<body class="bg-light">

<div class="container py-5">

    <div class="row justify-content-center">

        <div class="col-md-6 col-lg-5">

            <div class="card shadow-sm">

                <div class="card-body p-4">

                    <h2 class="text-center mb-2">
                        Shasta Exchange
                    </h2>

                    <p class="text-center text-muted mb-4">
                        Create your account
                    </p>


                    <?php if ($error): ?>

                        <div class="alert alert-danger">

                            <?= htmlspecialchars(
                                $error,
                                ENT_QUOTES,
                                'UTF-8'
                            ) ?>

                        </div>

                    <?php endif; ?>


                    <?php if ($success): ?>

                        <div class="alert alert-success">

                            <?= htmlspecialchars(
                                $success,
                                ENT_QUOTES,
                                'UTF-8'
                            ) ?>

                        </div>

                    <?php endif; ?>


                    <form method="POST">

                        <div class="mb-3">

                            <label class="form-label">
                                Email Address
                            </label>

                            <input
                                type="email"
                                name="email"
                                class="form-control"
                                maxlength="254"
                                autocomplete="email"
                                required>

                        </div>


                        <div class="mb-3">

                            <label class="form-label">
                                Password
                            </label>

                            <input
                                type="password"
                                name="password"
                                class="form-control"
                                minlength="8"
                                maxlength="72"
                                autocomplete="new-password"
                                required>

                        </div>


                        <div class="mb-3">

                            <label class="form-label">
                                Confirm Password
                            </label>

                            <input
                                type="password"
                                name="confirm_password"
                                class="form-control"
                                minlength="8"
                                maxlength="72"
                                autocomplete="new-password"
                                required>

                        </div>


                        <button
                            type="submit"
                            class="btn btn-primary w-100">

                            Create Account

                        </button>

                    </form>


                    <p class="text-center mt-4 mb-0">

                        Already have an account?

                        <a href="/auth/login.php">
                            Sign in
                        </a>

                    </p>

                </div>

            </div>

        </div>

    </div>

</div>

</body>
</html>
