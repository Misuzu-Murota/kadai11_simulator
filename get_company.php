<?php
// セッション開始
session_start();

// DB接続
require_once('funcs_local.php');
$pdo = db_conn();

// AJAXリクエストから企業名を取得
if (isset($_GET['query'])) {
    $query = $_GET['query'];
    var_dump($_GET['query']);
    // エラーハンドリング
    try {
        // テーブル名を company_master に変更
        $stmt = $pdo->prepare("SELECT company_name FROM company_master WHERE company_name LIKE :query LIMIT 10");
        $stmt->execute(['query' => '%' . $query . '%']);
        $companies = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // JSON形式で応答
        echo json_encode($companies);
    } catch (PDOException $e) {
        echo json_encode(['error' => 'Query failed: ' . $e->getMessage()]);
    }
    exit;
}