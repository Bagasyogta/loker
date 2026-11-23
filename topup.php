<?php
require_once __DIR__ . '/config/database.php';
requireLogin();

$user = getLoggedInUser();
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nominal = floatval($_POST['nominal'] ?? 0);

    if ($nominal < 5000) {
        $error = 'Nominal minimal top up adalah Rp 5.000!';
    } elseif ($nominal > 2000000) {
        $error = 'Nominal maksimal top up adalah Rp 2.000.000!';
    } else {
        $db = getDbConnection();
        $stmt = $db->prepare("UPDATE users SET saldo = saldo + ? WHERE id = ?");
        if ($stmt->execute([$nominal, $user['id']])) {
            $_SESSION['flash_success'] = 'Berhasil melakukan pengisian saldo simulasi sebesar ' . formatRupiah($nominal) . '! Saldo Anda kini diperbarui.';
            header("Location: dashboard.php");
            exit;
        } else {
            $error = 'Terjadi kesalahan saat memproses top up saldo.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Top Up Saldo - Smart Locker IoT</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .quick-amount {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 10px;
            margin-bottom: 16px;
        }
        .quick-btn {
            background: #f1f5f9;
            border: 1px solid var(--border);
            padding: 10px;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            text-align: center;
            transition: all 0.2s;
        }
        .quick-btn:hover {
            background: var(--primary-light);
            border-color: var(--primary);
            color: var(--primary);
        }
    </style>
</head>
<body>

<header class="navbar">
    <a href="dashboard.php" class="navbar-brand">
        <span class="brand-badge">IoT</span>
        <span>Smart Locker System</span>
    </a>
    <div class="navbar-user">
        <div class="user-badge">
            <span>👤 <?= htmlspecialchars($user['username']); ?></span>
            <span class="saldo-chip">Saldo: <?= formatRupiah($user['saldo']); ?></span>
        </div>
        <a href="dashboard.php" class="btn btn-sm btn-secondary">&larr; Kembali ke Dashboard</a>
    </div>
</header>

<main class="container" style="max-width: 520px;">
    <div class="card" style="margin-top: 20px;">
        <div class="card-header">
            <h2>Top Up Saldo Simulasi (Dummy)</h2>
        </div>
        <div class="card-body">
            <p style="font-size: 0.85rem; color: var(--text-muted); margin-bottom: 20px;">
                Fitur ini merupakan simulasi saldo digital untuk pengujian transaksi sewa loker cerdas tanpa integrasi payment gateway eksternal nyata (sesuai Bab 1.5 & Bab 3.4).
            </p>

            <?php if (!empty($error)): ?>
                <div class="alert alert-danger">
                    <span>⚠️</span> <div><?= htmlspecialchars($error); ?></div>
                </div>
            <?php endif; ?>

            <form method="POST" action="topup.php" id="formTopup">
                <label style="display: block; font-size: 0.85rem; font-weight: 600; margin-bottom: 8px;">Pilih Nominal Cepat:</label>
                <div class="quick-amount">
                    <button type="button" class="quick-btn" onclick="setNominal(10000)">Rp 10.000</button>
                    <button type="button" class="quick-btn" onclick="setNominal(25000)">Rp 25.000</button>
                    <button type="button" class="quick-btn" onclick="setNominal(50000)">Rp 50.000</button>
                    <button type="button" class="quick-btn" onclick="setNominal(100000)">Rp 100.000</button>
                </div>

                <div class="form-group">
                    <label for="nominal">Atau Masukkan Nominal Manual (Rp)</label>
                    <input type="number" id="nominal" name="nominal" class="form-control" placeholder="Contoh: 20000" min="5000" max="2000000" step="1000" required>
                </div>

                <button type="submit" class="btn btn-success btn-block" style="padding: 12px; font-size: 1rem;">
                    💳 Konfirmasi Pengisian Saldo
                </button>
            </form>
        </div>
    </div>
</main>

<script>
function setNominal(amount) {
    document.getElementById('nominal').value = amount;
}
</script>

</body>
</html>
