<?php
// Load dependencies
require_once __DIR__ . '/../../../config/api_secure_bootstrap.php';

try {
    
    // 1. Authenticate and Authorize Admin
    // using the $conn and $user_id variables provided by the bootstrap.
    
    if (!is_admin($conn, $user_id)) {
        throw new Exception("Forbidden. Admin privileges required.", 403);
    }

    // 2. Define allowed columns for sorting to prevent SQL injection
    $allowed_sort_columns = ['id', 'email', 'first_name', 'last_name', 'role', 'provider', 'created_at', 'country'];
    
    // 3. Get and validate query parameters from the front-end
    $search_email = $_GET['search_email'] ?? '';
    $search_name = $_GET['search_name'] ?? '';
    $filter_country = $_GET['filter_country'] ?? '';
    $start_date = $_GET['start_date'] ?? '';
    $end_date = $_GET['end_date'] ?? '';
    
    // Get the requested sort column, defaulting to 'created_at'
    $requested_sort_by = $_GET['sort_by'] ?? 'created_at';
    // Validate it against the allow-list. If invalid, fall back to the default.
    $sort_by = in_array($requested_sort_by, $allowed_sort_columns) ? $requested_sort_by : 'created_at';

    // Get and validate the sort order
    $requested_sort_order = strtoupper($_GET['sort_order'] ?? 'DESC');
    $sort_order = in_array($requested_sort_order, ['ASC', 'DESC']) ? $requested_sort_order : 'DESC';

    // 4. Dynamically build the SQL query
    $sql = "SELECT id, email, first_name, last_name, role, provider, created_at, country, is_suspended FROM users";
    $where_conditions = [];
    $params = [];
    $types = '';

    // Add email search condition
    if (!empty($search_email)) {
        $where_conditions[] = "email LIKE ?";
        $params[] = "%{$search_email}%";
        $types .= 's';
    }
    
    // Add name search condition
    if (!empty($search_name)) {
        $where_conditions[] = "(first_name LIKE ? OR last_name LIKE ?)";
        $name_term = "%{$search_name}%";
        $params[] = $name_term;
        $params[] = $name_term;
        $types .= 'ss';
    }
    
    // Add country filter condition
    if (!empty($filter_country)) {
        $where_conditions[] = "country = ?";
        $params[] = $filter_country;
        $types .= 's';
    }

    // Add date range conditions
    if (!empty($start_date)) {
        $where_conditions[] = "DATE(created_at) >= ?";
        $params[] = $start_date;
        $types .= 's';
    }
    if (!empty($end_date)) {
        $where_conditions[] = "DATE(created_at) <= ?";
        $params[] = $end_date;
        $types .= 's';
    }

    // Combine WHERE conditions
    if (count($where_conditions) > 0) {
        $sql .= " WHERE " . implode(' AND ', $where_conditions);
    }
    
    // Add sorting
    $sql .= " ORDER BY `$sort_by` $sort_order";

    // 5. Execute the query
    $stmt = $conn->prepare($sql);
    if (count($params) > 0) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();

    $users = [];
    while ($row = $result->fetch_assoc()) {
        $users[] = $row;
    }

    http_response_code(200);
    echo json_encode($users);

} catch (Exception $e) {
    $log->error('Admin fetch users failed.', ['error' => $e->getMessage()]);
    http_response_code($e->getCode() > 0 ? $e->getCode() : 500);
    echo json_encode(["message" => $e->getMessage()]);
    
} finally {
    if ($conn) $conn->close();
}
?>