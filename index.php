<?php 
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// セッション開始
session_start();

// DB接続
require_once('funcs.php');
$pdo = db_conn();

if (!$pdo) {
    exit("DB connection failed");
}
// echo "DB connection successful"; 

// 検索結果用の変数初期化
$company_info = [];
$competitors = [];
$female_manager_data = [];
$male_childcare_leave_data = [];
$profit_rate_data = [];
$roa_data = [];

// POSTで企業名が送信されたときの処理
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // echo "Form submitted"; // 追加
    // var_dump($_POST); // 受け取ったPOSTデータを表示

    // POSTデータが空かどうかを確認
    if (empty($_POST['company_name'])) {
        exit("No company name provided");
    }
    // echo "Company name received: " . htmlspecialchars($_POST['company_name']); 

    $company_name = trim($_POST['company_name']);
        
    // 企業名に基づいてSQLクエリを作成
    $sql = "SELECT * FROM company_master WHERE life_flg != 1 AND company_name LIKE :company_name";
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':company_name', '%' . $company_name . '%', PDO::PARAM_STR);
    
    // SQL実行
    if (!$stmt->execute()) {
        exit("SQL_ERROR: " . implode(", ", $stmt->errorInfo()));
    }
    
    // 検索結果の取得
    $company_info = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 該当企業と同じ業界の競合企業を取得
    if (!empty($company_info)) {
        $industry_detailed = $company_info[0]['industry_detailed'];
        $sql_competitors = "SELECT * FROM company_master WHERE industry_detailed = :industry_detailed AND life_flg != 1 AND company_name != :company_name";
        $stmt_competitors = $pdo->prepare($sql_competitors);
        $stmt_competitors->bindValue(':industry_detailed', $industry_detailed, PDO::PARAM_STR);
        $stmt_competitors->bindValue(':company_name', $company_info[0]['company_name'], PDO::PARAM_STR);
        
        if (!$stmt_competitors->execute()) {
            exit("SQL_ERROR: " . implode(", ", $stmt_competitors->errorInfo()));
        }

        // 競合企業の取得
        $competitors = $stmt_competitors->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // 該当企業および競合企業のcorporate_numberからデータを取得
    $corporate_numbers = array_column(array_merge($company_info, $competitors), 'corporate_number');
    
    // データを取得するための関数
    function fetchData($pdo, $corporate_numbers, $table_name) {
        if (empty($corporate_numbers)) return [];
        
        $placeholders = implode(',', array_fill(0, count($corporate_numbers), '?'));
        $sql = "
            SELECT cm.company_name, t.corporate_number, 
                   t.2019, t.2020, t.2021, t.2022, t.2023
            FROM {$table_name} t
            JOIN company_master cm ON t.corporate_number = cm.corporate_number
            WHERE t.corporate_number IN ($placeholders)";
        
        $stmt = $pdo->prepare($sql);
        if (!$stmt->execute($corporate_numbers)) {
            exit("SQL_ERROR: " . implode(", ", $stmt->errorInfo()));
        }
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // 各テーブルからデータを取得
    $female_manager_data = fetchData($pdo, $corporate_numbers, 'femalemanager');
    $male_childcare_leave_data = fetchData($pdo, $corporate_numbers, 'male_childcare_leave');
    $profit_rate_data = fetchData($pdo, $corporate_numbers, 'profit_rate');
    $roa_data = fetchData($pdo, $corporate_numbers, 'roa');

    // セッションにデータを保存
    $_SESSION['company_info'] = $company_info;
    $_SESSION['competitors'] = $competitors;
    $_SESSION['female_manager_data'] = $female_manager_data;
    $_SESSION['male_childcare_leave_data'] = $male_childcare_leave_data;
    $_SESSION['profit_rate_data'] = $profit_rate_data;
    $_SESSION['roa_data'] = $roa_data;

}

// 検索結果をセッションから取得
if (isset($_SESSION['company_info'])) {
    $company_info = $_SESSION['company_info'];
    $competitors = $_SESSION['competitors'];
    $female_manager_data = $_SESSION['female_manager_data'];
    $male_childcare_leave_data = $_SESSION['male_childcare_leave_data'];
    $profit_rate_data = $_SESSION['profit_rate_data'];
    $roa_data = $_SESSION['roa_data'];

    // セッションデータを削除してリロード時に保持されないようにする
    unset($_SESSION['company_info'], $_SESSION['competitors'], $_SESSION['female_manager_data'], $_SESSION['male_childcare_leave_data'], $_SESSION['profit_rate_data'], $_SESSION['roa_data']);
}
?>

<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>企業情報検索</title>
    <link rel="stylesheet" href="css/style.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>

<!-- 企業検索フォーム -->
<h1>企業別DE&I分析レポート</h1>
<div class="form-container">
    <!-- 企業検索フォーム -->
    <form method="POST" action="">
        <label for="company_name">企業検索:</label>
        <input type="text" id="company_name" name="company_name" placeholder="企業名を入力" oninput="fetchCompanies()" required>
        <button type="submit">検索</button>
    </form>
    <div id="suggestions" style="border: 1px solid #ccc; display: none; max-height: 200px; overflow-y: auto;"></div>
    <!-- 業種検索フォーム--> 
<form method="POST" action="">
    <label for="industry">業種選択:</label> 
     <input type="text" id="industry" name="industry" placeholder="業種を選択"  -->
    <button type="submit">検索</button>
 </form> 
      <!-- 業種詳細検索フォーム  -->
    <form method="POST" action="">
    <label for="industry_detailed">業種詳細検索:</label>
    <input type="text" id="industry_detailed" name="industry_detailed" placeholder="業種詳細を入力" -->
    <button type="submit">検索</button>
</form>
</div>
<hr>

<!-- 検索結果の表示 -->
<?php if (isset($_POST['company_name'])): ?>
    <h2>検索結果</h2>
    <?php if (!empty($company_info)): ?>
        <div class="information">
        <div>
        <h3>【該当企業情報】</h3>
        <ul>
            <li>企業名: <?= htmlspecialchars($company_info[0]['company_name']) ?></li>
            <li>業種: <?= htmlspecialchars($company_info[0]['industry']) ?></li>
            <li>業種詳細: <?= htmlspecialchars($company_info[0]['industry_detailed']) ?></li>
            <li>社員数: <?= htmlspecialchars($company_info[0]['number_of_employee']) ?></li>
        </ul>
        </div>
        <?php if (!empty($competitors)): ?>
        <div>
            <h3>【競合企業一覧】</h3>
            <h4>業種： <?= htmlspecialchars($company_info[0]['industry']) ?> - <?= htmlspecialchars($company_info[0]['industry_detailed']) ?></h4>
            <ul>
                <?php foreach ($competitors as $competitor): ?>
                    <li> <?= htmlspecialchars($competitor['company_name']) ?> - <?= htmlspecialchars($competitor['number_of_employee']) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        </div>
            <?php endif; ?>
    <?php endif; ?>

    <!-- 女性管理職比率推移 -->
    <?php if (!empty($female_manager_data)): ?>
    <div class="charts">
        <div class="chart-container">
        <h3>女性管理職比率推移</h3>
        <div class="chart-wrapper">
        <canvas id="femaleManagerChart" style="width: 200px; height: 400px;"></canvas>
           <div class="comments-section">
                <h4>現状分析</h4>
                <p> AIAPIにて現状分析を記入予定</p>
            </div>
        </div>
        </div>
    <!-- 男性育休取得率推移 -->
    <div class="chart-container">
        <h3>男性育休取得率推移</h3>
        <div class="chart-wrapper">
            <canvas id="maleChildcareLeaveChart" style="width: 200px; height: 400px;"></canvas>
            <div class="comments-section">
                <h4>現状分析</h4>
                <p> AIAPIにて現状分析を記入予定</p>
            </div>
        </div>
    </div> 
    <!-- 利益率推移 -->
    <div class="chart-container">
        <h3>利益率推移</h3>
        <div class="chart-wrapper">
            <canvas id="profit_rateChart" style="width: 200px; height: 400px;"></canvas>
            <div class="comments-section">
                <h4>現状分析</h4>
                <p> AIAPIにて現状分析を記入予定</p>
            </div>
        </div>
    </div>
    <!-- ROA推移 -->
    <div class="chart-container">
        <h3>roa推移</h3>
        <div class="chart-wrapper">
            <canvas id="roaChart" style="width: 200px; height: 400px;"></canvas>
            <div class="comments-section">
                <h4>現状分析</h4>
                <p> AIAPIにて現状分析を記入予定</p>
            </div>
        </div>
     </div>
    </div>   

<script>
// 候補先の表示
function fetchCompanies() {
    const query = document.getElementById('company_name').value;

    // AJAXリクエストを送信
    if (query.length > 0) { // 入力がある場合のみリクエストを送信
        const xhr = new XMLHttpRequest();
        xhr.open('GET', 'get_company.php?query=' + encodeURIComponent(query), true);
        xhr.onreadystatechange = function() {
            if (xhr.readyState === 4 && xhr.status === 200) {
                const suggestions = JSON.parse(xhr.responseText);
                displaySuggestions(suggestions);
            }
        };
        xhr.send();
    } else {
        document.getElementById('suggestions').innerHTML = '';
        document.getElementById('suggestions').style.display = 'none';
    }
}

function displaySuggestions(suggestions) {
    const suggestionsDiv = document.getElementById('suggestions');
    suggestionsDiv.innerHTML = ''; // 以前の候補をクリア
    if (suggestions.length > 0) {
        suggestions.forEach(company => {
            const div = document.createElement('div');
            div.textContent = company.company_name; // 会社名を表示
            div.onclick = function() {
                document.getElementById('company_name').value = company.company_name; // 入力フィールドに選択した会社名を表示
                suggestionsDiv.innerHTML = ''; // 候補をクリア
                suggestionsDiv.style.display = 'none'; // 候補を非表示
            };
            suggestionsDiv.appendChild(div);
        });
        suggestionsDiv.style.display = 'block'; // 候補を表示
    } else {
        suggestionsDiv.style.display = 'none'; // 候補がない場合は非表示
    }
}
// 以下グラフ

// 女性管理職比率
var ctx = document.getElementById('femaleManagerChart').getContext('2d');
   // 特定の企業のcorporate_numberを取得

// データを整理
var datasets = [];
const targetCorporateNumber = '<?= htmlspecialchars($company_info[0]['corporate_number']) ?>';

  // 特定企業のデータを追加
<?php foreach ($female_manager_data as $index => $data): ?>
    // 特定企業のデータを最初に追加
    if ('<?= htmlspecialchars($data['corporate_number']) ?>' === targetCorporateNumber) {
        datasets.unshift({
            label: '<?= htmlspecialchars($data['company_name']) ?>',
            data: [
                <?= htmlspecialchars($data['2019']) * 100 ?>, 
                <?= htmlspecialchars($data['2020']) * 100 ?>, 
                <?= htmlspecialchars($data['2021']) * 100 ?>, 
                <?= htmlspecialchars($data['2022']) * 100 ?>, 
                <?= htmlspecialchars($data['2023']) * 100 ?>
            ],
            borderColor: 'rgba(255, 99, 132, 1)', 
            backgroundColor: 'rgba(255, 99, 132, 1)',
            fill: false
        });
    } else {
        // その他の企業のデータを追加
        datasets.push({
            label: '<?= htmlspecialchars($data['company_name']) ?>',
            data: [
                <?= htmlspecialchars($data['2019']) * 100 ?>, 
                <?= htmlspecialchars($data['2020']) * 100 ?>, 
                <?= htmlspecialchars($data['2021']) * 100 ?>, 
                <?= htmlspecialchars($data['2022']) * 100 ?>, 
                <?= htmlspecialchars($data['2023']) * 100 ?>
            ],
            borderColor: 'rgba(75, 192, 192, 1)', 
            backgroundColor: 'rgba(75, 192, 192, 1)',
            fill: false
        });
    }
<?php endforeach; ?>
   // グラフの描画
var chart = new Chart(ctx, {
    type: 'line',
    data: {
        labels: ['2019', '2020', '2021', '2022', '2023'],
        datasets: datasets // 整理されたデータセットを使用
    },
    options: {
        maintainAspectRatio: false,
        responsive: true,
        scales: {
            y: {
                beginAtZero: true,
                title: {
                    display: true,
                    text: '比率 (%)'
                }
            }
        }

    }
});

// 男性育休取得率
var ctx_male = document.getElementById('maleChildcareLeaveChart').getContext('2d');
// データを整理
var maleDatasets = [];
// 特定企業のデータを追加
<?php foreach ($male_childcare_leave_data as $index => $data): ?>
    if ('<?= htmlspecialchars($data['corporate_number']) ?>' === targetCorporateNumber) {
        maleDatasets.unshift({
            label: '<?= htmlspecialchars($data['company_name']) ?>',
            data: [
                <?= htmlspecialchars($data['2019']) * 100 ?>, 
                <?= htmlspecialchars($data['2020']) * 100 ?>, 
                <?= htmlspecialchars($data['2021']) * 100 ?>, 
                <?= htmlspecialchars($data['2022']) * 100 ?>, 
                <?= htmlspecialchars($data['2023']) * 100 ?>
            ],
            borderColor: 'rgba(255, 99, 132, 1)',  // 特定企業の色を設定
            backgroundColor: 'rgba(255, 99, 132, 1)',
            fill: false
        });
    } else {
        maleDatasets.push({
            label: '<?= htmlspecialchars($data['company_name']) ?>',
            data: [
                <?= htmlspecialchars($data['2019']) * 100 ?>, 
                <?= htmlspecialchars($data['2020']) * 100 ?>, 
                <?= htmlspecialchars($data['2021']) * 100 ?>, 
                <?= htmlspecialchars($data['2022']) * 100 ?>, 
                <?= htmlspecialchars($data['2023']) * 100 ?>
            ],
            borderColor: 'rgba(75, 192, 192, 1)', // 他の企業の色を設定
            backgroundColor: 'rgba(75, 192, 192, 1)',
            fill: false
        });
    }
<?php endforeach; ?>
// グラフの描画
var chart_male = new Chart(ctx_male, {
    type: 'line',
    data: {
        labels: ['2019', '2020', '2021', '2022', '2023'],
        datasets: maleDatasets // 整理されたデータセットを使用
    },
    options: {
        maintainAspectRatio: false,
        responsive: true,
        scales: {
            y: {
                beginAtZero: true,
                title: {
                    display: true,
                    text: '比率 (%)'
                }
            }
        }
    }
});

// 利益率
var ctx_profitrate = document.getElementById('profit_rateChart').getContext('2d');
// データを整理
var profitDatasets = [];
// 特定企業のデータを追加
<?php foreach ($profit_rate_data as $index => $data): ?>
    if ('<?= htmlspecialchars($data['corporate_number']) ?>' === targetCorporateNumber) {
        profitDatasets.unshift({
            label: '<?= htmlspecialchars($data['company_name']) ?>',
            data: [
                <?= htmlspecialchars($data['2019']) * 100 ?>, 
                <?= htmlspecialchars($data['2020']) * 100 ?>, 
                <?= htmlspecialchars($data['2021']) * 100 ?>, 
                <?= htmlspecialchars($data['2022']) * 100 ?>, 
                <?= htmlspecialchars($data['2023']) * 100 ?>
            ],
            borderColor: 'rgba(255, 99, 132, 1)',
            backgroundColor: 'rgba(255, 99, 132, 1)',
            fill: false
        });
    } else {
        profitDatasets.push({
            label: '<?= htmlspecialchars($data['company_name']) ?>',
            data: [
                <?= htmlspecialchars($data['2019']) * 100 ?>, 
                <?= htmlspecialchars($data['2020']) * 100 ?>, 
                <?= htmlspecialchars($data['2021']) * 100 ?>, 
                <?= htmlspecialchars($data['2022']) * 100 ?>, 
                <?= htmlspecialchars($data['2023']) * 100 ?>
            ],
            borderColor: 'rgba(75, 192, 192, 1)',
            backgroundColor: 'rgba(75, 192, 192, 1)',
            fill: false
        });
    }
<?php endforeach; ?>

// グラフの描画
var chart_profitrate = new Chart(ctx_profitrate, {
    type: 'line',
    data: {
        labels: ['2019', '2020', '2021', '2022', '2023'],
        datasets: profitDatasets // 整理されたデータセットを使用
    },
    options: {
        maintainAspectRatio: false,
        responsive: true,
        scales: {
            y: {
                beginAtZero: true,
                title: {
                    display: true,
                    text: '比率 (%)'
                }
            }
        }
    }
});

// ROA
var ctx_roa = document.getElementById('roaChart').getContext('2d');
// データを整理
var roaDatasets = [];
// 特定企業のデータを追加
<?php foreach ($roa_data as $index => $data): ?>
    if ('<?= htmlspecialchars($data['corporate_number']) ?>' === targetCorporateNumber) {
        roaDatasets.unshift({
            label: '<?= htmlspecialchars($data['company_name']) ?>',
            data: [
                <?= htmlspecialchars($data['2019']) * 100 ?>, 
                <?= htmlspecialchars($data['2020']) * 100 ?>, 
                <?= htmlspecialchars($data['2021']) * 100 ?>, 
                <?= htmlspecialchars($data['2022']) * 100 ?>, 
                <?= htmlspecialchars($data['2023']) * 100 ?>
            ],
            borderColor: 'rgba(255, 99, 132, 1)',
            backgroundColor: 'rgba(255, 99, 132, 1)',
            fill: false
        });
    } else {
        roaDatasets.push({
            label: '<?= htmlspecialchars($data['company_name']) ?>',
            data: [
                <?= htmlspecialchars($data['2019']) * 100 ?>, 
                <?= htmlspecialchars($data['2020']) * 100 ?>, 
                <?= htmlspecialchars($data['2021']) * 100 ?>, 
                <?= htmlspecialchars($data['2022']) * 100 ?>, 
                <?= htmlspecialchars($data['2023']) * 100 ?>
            ],
            borderColor: 'rgba(75, 192, 192, 1)',
            backgroundColor: 'rgba(75, 192, 192, 1)',
            fill: false
        });
    }
<?php endforeach; ?>

// グラフの描画
var chart_roa = new Chart(ctx_roa, {
    type: 'line',
    data: {
        labels: ['2019', '2020', '2021', '2022', '2023'],
        datasets: roaDatasets // 整理されたデータセットを使用
    },
    options: {
        maintainAspectRatio: false,
        responsive: true,
        scales: {
            y: {
                beginAtZero: true,
                title: {
                    display: true,
                    text: '比率 (%)'
                }
            }
        }
    }
});
        </script>
 
 <?php endif; ?>
 <?php endif; ?>
</body>
</html>
