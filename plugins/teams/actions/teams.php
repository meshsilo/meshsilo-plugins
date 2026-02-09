<?php
/**
 * Team/Organization Workspaces Actions
 */

require_once __DIR__ . '/../../../includes/config.php';
require_once __DIR__ . '/../../../includes/features.php';

header('Content-Type: application/json');

// Check if teams feature is enabled
if (!isFeatureEnabled('teams')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Teams feature is disabled']);
    exit;
}

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'error' => 'Not logged in']);
    exit;
}

// CSRF validation for POST requests (state-changing operations)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !Csrf::check()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid request token']);
    exit;
}

$user = getCurrentUser();
$action = $_POST['action'] ?? $_GET['action'] ?? '';

switch ($action) {
    // Team management
    case 'create_team':
        createTeam();
        break;
    case 'update_team':
        updateTeam();
        break;
    case 'delete_team':
        deleteTeam();
        break;
    case 'list_teams':
        listTeams();
        break;
    case 'get_team':
        getTeam();
        break;

    // Membership
    case 'add_member':
        addMember();
        break;
    case 'remove_member':
        removeMember();
        break;
    case 'update_role':
        updateMemberRole();
        break;
    case 'list_members':
        listMembers();
        break;

    // Team models
    case 'share_model':
        shareModelWithTeam();
        break;
    case 'unshare_model':
        unshareModelFromTeam();
        break;
    case 'list_team_models':
        listTeamModels();
        break;

    // Invitations
    case 'invite':
        inviteToTeam();
        break;
    case 'accept_invite':
        acceptInvite();
        break;
    case 'decline_invite':
        declineInvite();
        break;
    case 'list_invites':
        listInvites();
        break;

    default:
        echo json_encode(['success' => false, 'error' => 'Invalid action']);
}

function createTeam() {
    global $user;

    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');

    if (empty($name)) {
        echo json_encode(['success' => false, 'error' => 'Team name required']);
        return;
    }

    $db = getDB();

    // Create team
    $stmt = $db->prepare('
        INSERT INTO teams (name, description, owner_id, created_at)
        VALUES (:name, :description, :owner_id, CURRENT_TIMESTAMP)
    ');
    $stmt->execute([
        ':name' => $name,
        ':description' => $description,
        ':owner_id' => $user['id']
    ]);

    $teamId = $db->lastInsertId();

    // Add owner as admin member
    $stmt = $db->prepare('
        INSERT INTO team_members (team_id, user_id, role, joined_at)
        VALUES (:team_id, :user_id, "admin", CURRENT_TIMESTAMP)
    ');
    $stmt->execute([':team_id' => $teamId, ':user_id' => $user['id']]);

    echo json_encode([
        'success' => true,
        'team_id' => $teamId
    ]);
}

function updateTeam() {
    global $user;

    $teamId = (int)($_POST['team_id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');

    if (!$teamId) {
        echo json_encode(['success' => false, 'error' => 'Team ID required']);
        return;
    }

    if (!canManageTeam($teamId)) {
        echo json_encode(['success' => false, 'error' => 'Not authorized']);
        return;
    }

    $db = getDB();
    $stmt = $db->prepare('UPDATE teams SET name = :name, description = :description WHERE id = :id');
    $stmt->execute([':name' => $name, ':description' => $description, ':id' => $teamId]);

    echo json_encode(['success' => true]);
}

function deleteTeam() {
    global $user;

    $teamId = (int)($_POST['team_id'] ?? 0);

    $db = getDB();
    $stmt = $db->prepare('SELECT owner_id FROM teams WHERE id = :id');
    $stmt->execute([':id' => $teamId]);
    $team = $stmt->fetch();

    if (!$team || $team['owner_id'] != $user['id']) {
        echo json_encode(['success' => false, 'error' => 'Only owner can delete team']);
        return;
    }

    // Delete related data
    $db->prepare('DELETE FROM team_models WHERE team_id = :id')->execute([':id' => $teamId]);
    $db->prepare('DELETE FROM team_members WHERE team_id = :id')->execute([':id' => $teamId]);
    $db->prepare('DELETE FROM team_invites WHERE team_id = :id')->execute([':id' => $teamId]);
    $db->prepare('DELETE FROM teams WHERE id = :id')->execute([':id' => $teamId]);

    echo json_encode(['success' => true]);
}

function listTeams() {
    global $user;
    $db = getDB();

    $stmt = $db->prepare('
        SELECT t.*, tm.role as my_role,
               (SELECT COUNT(*) FROM team_members WHERE team_id = t.id) as member_count,
               (SELECT COUNT(*) FROM team_models WHERE team_id = t.id) as model_count
        FROM teams t
        JOIN team_members tm ON t.id = tm.team_id
        WHERE tm.user_id = :user_id
        ORDER BY t.name
    ');
    $stmt->execute([':user_id' => $user['id']]);

    $teams = [];
    while ($row = $stmt->fetch()) {
        $teams[] = $row;
    }

    echo json_encode(['success' => true, 'teams' => $teams]);
}

function getTeam() {
    global $user;

    $teamId = (int)($_GET['team_id'] ?? 0);

    if (!isMemberOfTeam($teamId)) {
        echo json_encode(['success' => false, 'error' => 'Not a member of this team']);
        return;
    }

    $db = getDB();
    $stmt = $db->prepare('SELECT * FROM teams WHERE id = :id');
    $stmt->execute([':id' => $teamId]);
    $team = $stmt->fetch();

    if (!$team) {
        echo json_encode(['success' => false, 'error' => 'Team not found']);
        return;
    }

    // Get members
    $stmt = $db->prepare('
        SELECT u.id, u.username, u.email, tm.role, tm.joined_at
        FROM team_members tm
        JOIN users u ON tm.user_id = u.id
        WHERE tm.team_id = :team_id
        ORDER BY tm.role DESC, u.username
    ');
    $stmt->execute([':team_id' => $teamId]);

    $members = [];
    while ($row = $stmt->fetch()) {
        $members[] = $row;
    }

    $team['members'] = $members;

    echo json_encode(['success' => true, 'team' => $team]);
}

function addMember() {
    global $user;

    $teamId = (int)($_POST['team_id'] ?? 0);
    $userId = (int)($_POST['user_id'] ?? 0);
    $role = $_POST['role'] ?? 'member';

    if (!canManageTeam($teamId)) {
        echo json_encode(['success' => false, 'error' => 'Not authorized']);
        return;
    }

    $db = getDB();

    // Check if already member
    $stmt = $db->prepare('SELECT 1 FROM team_members WHERE team_id = :team_id AND user_id = :user_id');
    $stmt->execute([':team_id' => $teamId, ':user_id' => $userId]);
    if ($stmt->fetch()) {
        echo json_encode(['success' => false, 'error' => 'Already a member']);
        return;
    }

    $stmt = $db->prepare('
        INSERT INTO team_members (team_id, user_id, role, joined_at)
        VALUES (:team_id, :user_id, :role, CURRENT_TIMESTAMP)
    ');
    $stmt->execute([':team_id' => $teamId, ':user_id' => $userId, ':role' => $role]);

    echo json_encode(['success' => true]);
}

function removeMember() {
    global $user;

    $teamId = (int)($_POST['team_id'] ?? 0);
    $userId = (int)($_POST['user_id'] ?? 0);

    $db = getDB();

    // Check if owner
    $stmt = $db->prepare('SELECT owner_id FROM teams WHERE id = :id');
    $stmt->execute([':id' => $teamId]);
    $team = $stmt->fetch();

    if ($team['owner_id'] == $userId) {
        echo json_encode(['success' => false, 'error' => 'Cannot remove team owner']);
        return;
    }

    // Allow self-removal or admin removal
    if ($userId != $user['id'] && !canManageTeam($teamId)) {
        echo json_encode(['success' => false, 'error' => 'Not authorized']);
        return;
    }

    $stmt = $db->prepare('DELETE FROM team_members WHERE team_id = :team_id AND user_id = :user_id');
    $stmt->execute([':team_id' => $teamId, ':user_id' => $userId]);

    echo json_encode(['success' => true]);
}

function updateMemberRole() {
    global $user;

    $teamId = (int)($_POST['team_id'] ?? 0);
    $userId = (int)($_POST['user_id'] ?? 0);
    $role = $_POST['role'] ?? 'member';

    if (!canManageTeam($teamId)) {
        echo json_encode(['success' => false, 'error' => 'Not authorized']);
        return;
    }

    $db = getDB();
    $stmt = $db->prepare('UPDATE team_members SET role = :role WHERE team_id = :team_id AND user_id = :user_id');
    $stmt->execute([':role' => $role, ':team_id' => $teamId, ':user_id' => $userId]);

    echo json_encode(['success' => true]);
}

function listMembers() {
    $teamId = (int)($_GET['team_id'] ?? 0);

    if (!isMemberOfTeam($teamId)) {
        echo json_encode(['success' => false, 'error' => 'Not a member']);
        return;
    }

    $db = getDB();
    $stmt = $db->prepare('
        SELECT u.id, u.username, u.email, tm.role, tm.joined_at
        FROM team_members tm
        JOIN users u ON tm.user_id = u.id
        WHERE tm.team_id = :team_id
        ORDER BY tm.role DESC, u.username
    ');
    $stmt->execute([':team_id' => $teamId]);

    $members = [];
    while ($row = $stmt->fetch()) {
        $members[] = $row;
    }

    echo json_encode(['success' => true, 'members' => $members]);
}

function shareModelWithTeam() {
    global $user;

    $teamId = (int)($_POST['team_id'] ?? 0);
    $modelId = (int)($_POST['model_id'] ?? 0);
    $permissions = $_POST['permissions'] ?? 'read';

    if (!isMemberOfTeam($teamId)) {
        echo json_encode(['success' => false, 'error' => 'Not a member']);
        return;
    }

    // Verify model ownership
    $db = getDB();
    $stmt = $db->prepare('SELECT uploaded_by FROM models WHERE id = :id');
    $stmt->execute([':id' => $modelId]);
    $model = $stmt->fetch();

    if (!$model || $model['uploaded_by'] != $user['id']) {
        echo json_encode(['success' => false, 'error' => 'Not authorized to share this model']);
        return;
    }

    // Share with team
    $stmt = $db->prepare('
        INSERT OR REPLACE INTO team_models (team_id, model_id, shared_by, permissions, shared_at)
        VALUES (:team_id, :model_id, :shared_by, :permissions, CURRENT_TIMESTAMP)
    ');
    $stmt->execute([
        ':team_id' => $teamId,
        ':model_id' => $modelId,
        ':shared_by' => $user['id'],
        ':permissions' => $permissions
    ]);

    echo json_encode(['success' => true]);
}

function unshareModelFromTeam() {
    global $user;

    $teamId = (int)($_POST['team_id'] ?? 0);
    $modelId = (int)($_POST['model_id'] ?? 0);

    $db = getDB();

    // Check if shared by this user or user is admin
    $stmt = $db->prepare('SELECT shared_by FROM team_models WHERE team_id = :team_id AND model_id = :model_id');
    $stmt->execute([':team_id' => $teamId, ':model_id' => $modelId]);
    $share = $stmt->fetch();

    if (!$share || ($share['shared_by'] != $user['id'] && !canManageTeam($teamId))) {
        echo json_encode(['success' => false, 'error' => 'Not authorized']);
        return;
    }

    $stmt = $db->prepare('DELETE FROM team_models WHERE team_id = :team_id AND model_id = :model_id');
    $stmt->execute([':team_id' => $teamId, ':model_id' => $modelId]);

    echo json_encode(['success' => true]);
}

function listTeamModels() {
    $teamId = (int)($_GET['team_id'] ?? 0);

    if (!isMemberOfTeam($teamId)) {
        echo json_encode(['success' => false, 'error' => 'Not a member']);
        return;
    }

    $db = getDB();
    $stmt = $db->prepare('
        SELECT m.*, tm.permissions, tm.shared_at, u.username as shared_by_username
        FROM team_models tm
        JOIN models m ON tm.model_id = m.id
        JOIN users u ON tm.shared_by = u.id
        WHERE tm.team_id = :team_id
        ORDER BY tm.shared_at DESC
    ');
    $stmt->execute([':team_id' => $teamId]);

    $models = [];
    while ($row = $stmt->fetch()) {
        $models[] = $row;
    }

    echo json_encode(['success' => true, 'models' => $models]);
}

function inviteToTeam() {
    global $user;

    $teamId = (int)($_POST['team_id'] ?? 0);
    $email = trim($_POST['email'] ?? '');
    $role = $_POST['role'] ?? 'member';

    if (!canManageTeam($teamId)) {
        echo json_encode(['success' => false, 'error' => 'Not authorized']);
        return;
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['success' => false, 'error' => 'Invalid email']);
        return;
    }

    $db = getDB();

    // Check if already invited
    $stmt = $db->prepare('SELECT 1 FROM team_invites WHERE team_id = :team_id AND email = :email AND status = "pending"');
    $stmt->execute([':team_id' => $teamId, ':email' => $email]);
    if ($stmt->fetch()) {
        echo json_encode(['success' => false, 'error' => 'Already invited']);
        return;
    }

    // Check if already member
    $stmt = $db->prepare('
        SELECT 1 FROM team_members tm JOIN users u ON tm.user_id = u.id
        WHERE tm.team_id = :team_id AND u.email = :email
    ');
    $stmt->execute([':team_id' => $teamId, ':email' => $email]);
    if ($stmt->fetch()) {
        echo json_encode(['success' => false, 'error' => 'Already a member']);
        return;
    }

    $token = bin2hex(random_bytes(32));

    $stmt = $db->prepare('
        INSERT INTO team_invites (team_id, email, role, token, invited_by, created_at, status)
        VALUES (:team_id, :email, :role, :token, :invited_by, CURRENT_TIMESTAMP, "pending")
    ');
    $stmt->execute([
        ':team_id' => $teamId,
        ':email' => $email,
        ':role' => $role,
        ':token' => $token,
        ':invited_by' => $user['id']
    ]);

    echo json_encode([
        'success' => true,
        'invite_token' => $token,
        'invite_url' => SITE_URL . '/invite.php?token=' . $token
    ]);
}

function acceptInvite() {
    global $user;

    $token = $_POST['token'] ?? '';

    $db = getDB();
    $stmt = $db->prepare('
        SELECT * FROM team_invites
        WHERE token = :token AND status = "pending" AND email = :email
    ');
    $stmt->execute([':token' => $token, ':email' => $user['email']]);
    $invite = $stmt->fetch();

    if (!$invite) {
        echo json_encode(['success' => false, 'error' => 'Invalid or expired invite']);
        return;
    }

    // Add as member
    $stmt = $db->prepare('
        INSERT INTO team_members (team_id, user_id, role, joined_at)
        VALUES (:team_id, :user_id, :role, CURRENT_TIMESTAMP)
    ');
    $stmt->execute([
        ':team_id' => $invite['team_id'],
        ':user_id' => $user['id'],
        ':role' => $invite['role']
    ]);

    // Update invite status
    $stmt = $db->prepare('UPDATE team_invites SET status = "accepted" WHERE id = :id');
    $stmt->execute([':id' => $invite['id']]);

    echo json_encode(['success' => true, 'team_id' => $invite['team_id']]);
}

function declineInvite() {
    global $user;

    $token = $_POST['token'] ?? '';

    $db = getDB();
    $stmt = $db->prepare('
        UPDATE team_invites SET status = "declined"
        WHERE token = :token AND email = :email
    ');
    $stmt->execute([':token' => $token, ':email' => $user['email']]);

    echo json_encode(['success' => true]);
}

function listInvites() {
    global $user;

    $db = getDB();
    $stmt = $db->prepare('
        SELECT ti.*, t.name as team_name, u.username as invited_by_username
        FROM team_invites ti
        JOIN teams t ON ti.team_id = t.id
        JOIN users u ON ti.invited_by = u.id
        WHERE ti.email = :email AND ti.status = "pending"
        ORDER BY ti.created_at DESC
    ');
    $stmt->execute([':email' => $user['email']]);

    $invites = [];
    while ($row = $stmt->fetch()) {
        $invites[] = $row;
    }

    echo json_encode(['success' => true, 'invites' => $invites]);
}

// Helper functions
function isMemberOfTeam($teamId) {
    global $user;
    $db = getDB();
    $stmt = $db->prepare('SELECT 1 FROM team_members WHERE team_id = :team_id AND user_id = :user_id');
    $stmt->execute([':team_id' => $teamId, ':user_id' => $user['id']]);
    return (bool)$stmt->fetch();
}

function canManageTeam($teamId) {
    global $user;
    $db = getDB();

    // Check if owner or admin
    $stmt = $db->prepare('SELECT owner_id FROM teams WHERE id = :id');
    $stmt->execute([':id' => $teamId]);
    $team = $stmt->fetch();

    if ($team && $team['owner_id'] == $user['id']) {
        return true;
    }

    $stmt = $db->prepare('SELECT role FROM team_members WHERE team_id = :team_id AND user_id = :user_id');
    $stmt->execute([':team_id' => $teamId, ':user_id' => $user['id']]);
    $member = $stmt->fetch();

    return $member && $member['role'] === 'admin';
}
