<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

if (!empty($_SESSION['user_id'])) {
    header('Location: /voicemail.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $email = strtolower(trim($_POST['email'] ?? ''));
    $password = $_POST['password'] ?? '';

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } else {

        try {

            $stmt = $pdo->prepare(
                'SELECT id, email, password_hash, verified
                 FROM users
                 WHERE email = ?
                 LIMIT 1'
            );

            $stmt->execute([$email]);

            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$user || !password_verify($password, $user['password_hash'])) {

                $error = 'Incorrect email address or password.';

            } elseif ((int)$user['verified'] !== 1) {

                $error = 'Please verify your email address before logging in.';

            } else {

                session_regenerate_id(true);

                $_SESSION['user_id'] = (int)$user['id'];
                $_SESSION['email'] = $user['email'];

                header('Location: /voicemail.php');
                exit;

            }

        } catch (PDOException $e) {

            error_log('Login database error: ' . $e->getMessage());
            $error = 'Unable to log in right now. Please try again.';

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

    <title>Login - Shasta Exchange</title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet">

    <style>

        body {
            background: #f5f7fa;
            min-height: 100vh;
        }

        .brand {
            font-weight: 700;
            letter-spacing: -0.5px;
        }

        .login-card {
            border: none;
            border-radius: 14px;
        }

        .login-card .card-body {
            padding: 2rem;
        }

        .form-control {
            padding: 12px;
            border-radius: 8px;
        }

        .btn-primary {
            padding: 12px;
            border-radius: 8px;
            font-weight: 600;
        }

    </style>

</head>

<body>

<div class="container py-5">

    <div class="row justify-content-center">

        <div class="col-md-6 col-lg-5">

            <div class="card login-card shadow-sm">

                <div class="card-body">

                    <h2 class="text-center brand mb-2">
                        Shasta Exchange
                    </h2>

                    <p class="text-center text-muted mb-4">
                        Sign in to your account
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


                        <div class="mb-4">

                            <label class="form-label">
                                Password
                            </label>

                            <input
                                type="password"
                                name="password"
                                class="form-control"
                                autocomplete="current-password"
                                required>

                        </div>


                        <button
                            type="submit"
                            class="btn btn-primary w-100">

                            Sign In

                        </button>

                    </form>


                    <div class="text-center mt-4">

                        <span class="text-muted">
                            Don't have an account?
                        </span>

                        <a href="/auth/register.php">
                            Create Account
                        </a>

                    </div>

                </div>

            </div>

            <p class="text-center text-muted mt-4 small">
                Shasta Exchange &copy; <?= date('Y') ?>
            </p>

        </div>

    </div>

</div>

</body>
</html>
