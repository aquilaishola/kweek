<?php
require_once __DIR__ . '/includes/functions.php';

$db    = db();
$token = clean(get('token'));

$invite = null;
$owner  = null;
$error  = '';

if (empty($token)) {
    $error = 'Missing invite link.';
} else {
    $stmt = $db->prepare("SELECT * FROM team_invites WHERE token = ? LIMIT 1");
    $stmt->execute([$token]);
    $invite = $stmt->fetch();

    if (!$invite) {
        $error = 'This invite link is invalid.';
    } elseif ($invite['status'] === 'revoked') {
        $error = 'This invite has been revoked by the account owner.';
    } else {
        $ownerStmt = $db->prepare("SELECT name, email FROM users WHERE id = ?");
        $ownerStmt->execute([$invite['owner_user_id']]);
        $owner = $ownerStmt->fetch();
    }
}

// Does a users row already exist for the invited email? Under normal
// use this should be impossible (team.php blocks inviting an existing
// account), but the invite could predate that check, or the email could
// have signed up independently in the meantime — so it's handled here
// defensively rather than assumed away.
$existingAccount = null;
if ($invite && !$error) {
    $existingStmt = $db->prepare("SELECT id, email FROM users WHERE email = ? LIMIT 1");
    $existingStmt->execute([$invite['email']]);
    $existingAccount = $existingStmt->fetch() ?: null;
}

$loggedInAsInvitee = $invite && isLoggedIn()
    && $existingAccount
    && currentUserId() === (int)$existingAccount['id'];

$formErrors = [];
$justAccepted = false;

// ── ACCEPT ────────────────────────────────────────────────────
if ($invite && $invite['status'] === 'pending' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    if ($existingAccount && !$loggedInAsInvitee) {
        // There's already a users row for this email but the browser
        // isn't authenticated as that user — don't link accounts on
        // someone's behalf without proof they own the login.
        $formErrors[] = 'An account already exists for this email. Log in with it first, then come back and accept.';
    } elseif ($existingAccount && $loggedInAsInvitee) {
        // Already logged in as the invited email — just link the two.
        $db->prepare("UPDATE users SET owner_user_id=?, team_role=? WHERE id=?")
           ->execute([$invite['owner_user_id'], $invite['role'], $existingAccount['id']]);
        $db->prepare("UPDATE team_invites SET status='accepted', accepted_at=NOW(), user_id=? WHERE id=?")
           ->execute([$existingAccount['id'], $invite['id']]);
        $invite['status'] = 'accepted';
        $justAccepted = true;
    } else {
        // No account yet — create one right here as part of accepting.
        $name     = clean(post('name'));
        $password = post('password');
        $confirm  = post('confirm_password');

        if (strlen($name) < 2) $formErrors[] = 'Enter your full name.';
        if (strlen($password) < 8) $formErrors[] = 'Password must be at least 8 characters.';
        if ($password !== $confirm) $formErrors[] = 'Passwords do not match.';

        if (empty($formErrors)) {
            $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
            $db->prepare("INSERT INTO users (name, email, password, plan, status, owner_user_id, team_role, monthly_reset, created_at)
                          VALUES (?, ?, ?, 'free', 'active', ?, ?, ?, NOW())")
               ->execute([$name, $invite['email'], $hash, $invite['owner_user_id'], $invite['role'], date('Y-m-01')]);
            $newUserId = (int)$db->lastInsertId();

            $db->prepare("UPDATE team_invites SET status='accepted', accepted_at=NOW(), user_id=? WHERE id=?")
               ->execute([$newUserId, $invite['id']]);

            // Log them straight in — no separate "now go sign in" step.
            $_SESSION['user_id'] = $newUserId;
            $invite['status'] = 'accepted';
            $justAccepted = true;
        }
    }
}

$pageTitle = 'Team Invite';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($pageTitle) ?> — Kweek</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@latest/tabler-icons.min.css">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Space Grotesk',sans-serif;background:#F2F0FC;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px;color:#15131F}
.box{background:#fff;border:1px solid #E4E3EE;border-radius:16px;padding:36px;max-width:440px;width:100%;text-align:center;box-shadow:0 24px 60px -24px rgba(20,15,60,.18)}
.logo-mark{width:44px;height:44px;border-radius:12px;background:#4F43D4;display:flex;align-items:center;justify-content:center;margin:0 auto 20px}
.logo-mark svg{width:24px;height:24px}
.icon{width:56px;height:56px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:26px;margin:0 auto 16px}
.icon.ok{background:#E9F9F0;color:#1E9E5A}
.icon.warn{background:#FEF2F2;color:#DC2626}
h2{font-size:19px;font-weight:700;margin-bottom:8px;letter-spacing:-.3px}
p{font-size:14px;color:#6B6879;line-height:1.6;margin-bottom:22px}
.role-badge{display:inline-flex;align-items:center;gap:6px;background:#F2F0FC;color:#4F43D4;font-size:12px;font-weight:700;padding:5px 12px;border-radius:9999px;margin-bottom:18px;text-transform:capitalize}
.btn{display:inline-flex;align-items:center;gap:8px;background:#4F43D4;color:#fff;border:none;padding:13px 24px;border-radius:10px;font-family:inherit;font-size:14.5px;font-weight:700;cursor:pointer;text-decoration:none;width:100%;justify-content:center}
.btn:hover{background:#3D33B8}
.note{margin-top:20px;font-size:12.5px;color:#6B6879;background:#F9F9FB;border-radius:10px;padding:12px 14px;text-align:left;line-height:1.6}
.field{text-align:left;margin-bottom:14px}
.field label{display:block;font-size:12.5px;font-weight:600;color:#15131F;margin-bottom:6px}
.field input{width:100%;font-family:inherit;font-size:14px;color:#15131F;background:#F9F9FB;border:1.5px solid #E4E3EE;border-radius:9px;padding:11px 13px;outline:none;transition:border-color .15s}
.field input:focus{border-color:#4F43D4;background:#fff}
.form-errors{text-align:left;background:#FEF2F2;border:1px solid #FCA5A5;border-radius:10px;padding:12px 14px;margin-bottom:18px}
.form-errors p{font-size:12.5px;color:#DC2626;margin:0}
.form-errors p+p{margin-top:4px}
.email-lock{display:flex;align-items:center;gap:8px;background:#F9F9FB;border:1.5px solid #E4E3EE;border-radius:9px;padding:11px 13px;font-size:13.5px;color:#6B6879;margin-bottom:16px}
</style>
</head>
<body>
<div class="box">
  <div class="logo-mark"><svg viewBox="0 0 64 64"><path d="M22 12 H30 V30 L44 12 H53 L36 32 L54 52 H45 L30 34 V52 H22 Z" fill="#FFFFFF"/><rect x="14" y="26" width="36" height="4" fill="#4F43D4"/><rect x="14" y="36" width="36" height="4" fill="#4F43D4"/></svg></div>

  <?php if ($error): ?>
    <div class="icon warn"><i class="ti ti-link-off"></i></div>
    <h2>Invite Not Found</h2>
    <p><?= htmlspecialchars($error) ?></p>
    <a href="/" class="btn"><i class="ti ti-home"></i> Go to Kweek</a>

  <?php elseif ($invite['status'] === 'accepted' || $justAccepted): ?>
    <div class="icon ok"><i class="ti ti-circle-check"></i></div>
    <h2>You're in!</h2>
    <p>You've accepted <strong><?= htmlspecialchars($owner['name'] ?? 'the owner') ?></strong>'s invite as a <strong><?= htmlspecialchars(ucfirst($invite['role'])) ?></strong>.</p>
    <?php if ($justAccepted && isLoggedIn()): ?>
      <a href="/dashboard.php" class="btn"><i class="ti ti-arrow-right"></i> Go to Dashboard</a>
    <?php else: ?>
      <a href="/login.php" class="btn"><i class="ti ti-login"></i> Sign in to continue</a>
    <?php endif; ?>

  <?php else: ?>
    <div class="icon ok"><i class="ti ti-mail-opened"></i></div>
    <h2>You've been invited</h2>
    <span class="role-badge"><i class="ti ti-shield"></i> <?= htmlspecialchars($invite['role']) ?></span>
    <p><strong><?= htmlspecialchars($owner['name'] ?? 'A Kweek merchant') ?></strong> invited <strong><?= htmlspecialchars($invite['email']) ?></strong> to help manage their Kweek account.</p>

    <?php if (!empty($formErrors)): ?>
    <div class="form-errors">
      <?php foreach ($formErrors as $fe): ?><p><i class="ti ti-alert-circle"></i> <?= htmlspecialchars($fe) ?></p><?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if ($existingAccount && !$loggedInAsInvitee): ?>
      <!-- Account already exists for this email — they need to prove it's
           theirs by logging in before we'll link it to the owner. -->
      <div class="email-lock"><i class="ti ti-info-circle"></i> This email already has a Kweek login.</div>
      <a href="/login.php?redirect=<?= urlencode('/team-invite.php?token=' . $token) ?>" class="btn"><i class="ti ti-login"></i> Log in to accept</a>
    <?php elseif ($existingAccount && $loggedInAsInvitee): ?>
      <form method="POST">
        <?= csrfField() ?>
        <button type="submit" class="btn"><i class="ti ti-check"></i> Accept Invite</button>
      </form>
    <?php else: ?>
      <!-- New person — create their login as part of accepting. -->
      <form method="POST">
        <?= csrfField() ?>
        <div class="field">
          <label>Full Name</label>
          <input type="text" name="name" placeholder="Your name" value="<?= htmlspecialchars(post('name')) ?>" required>
        </div>
        <div class="field">
          <label>Set a Password</label>
          <input type="password" name="password" placeholder="Min. 8 characters" required>
        </div>
        <div class="field">
          <label>Confirm Password</label>
          <input type="password" name="confirm_password" placeholder="Repeat password" required>
        </div>
        <button type="submit" class="btn"><i class="ti ti-check"></i> Accept &amp; Create Login</button>
      </form>
    <?php endif; ?>
  <?php endif; ?>
</div>
</body>
</html>