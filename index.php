<?php
require_once 'db.php';

session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

// Загружаем данные пользователя
$stmt = $pdo->prepare("SELECT username, rating FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch();
if (!$user) {
    session_destroy();
    header("Location: login.php");
    exit;
}
$_SESSION['user'] = $user['username'];
$_SESSION['rating'] = $user['rating'];

// Получаем топ-10 для рейтинга
$topPlayers = $pdo->query("SELECT username, rating FROM users ORDER BY rating DESC LIMIT 10")->fetchAll();

function initBoard() {
    return [
        ["bR","bN","bB","bQ","bK","bB","bN","bR"],
        ["bP","bP","bP","bP","bP","bP","bP","bP"],
        ["","","","","","","",""],
        ["","","","","","","",""],
        ["","","","","","","",""],
        ["","","","","","","",""],
        ["wP","wP","wP","wP","wP","wP","wP","wP"],
        ["wR","wN","wB","wQ","wK","wB","wN","wR"]
    ];
}

if (!isset($_SESSION['board'])) {
    $_SESSION['board'] = initBoard();
    $_SESSION['turn'] = 'w'; // игрок за белых
    $_SESSION['captured_w'] = [];
    $_SESSION['captured_b'] = [];
    $_SESSION['selected'] = null;
    $_SESSION['moves'] = [];
    $_SESSION['game_over'] = false;
    $_SESSION['winner'] = null;
}

// Новая игра
if (isset($_POST['newgame'])) {
    $_SESSION['board'] = initBoard();
    $_SESSION['turn'] = 'w';
    $_SESSION['captured_w'] = [];
    $_SESSION['captured_b'] = [];
    $_SESSION['selected'] = null;
    $_SESSION['moves'] = [];
    $_SESSION['game_over'] = false;
    $_SESSION['winner'] = null;
}

// Функции для ходов (без изменений)
function validCell($r, $c) {
    return $r >= 0 && $r < 8 && $c >= 0 && $c < 8;
}

function rayMoves($b, $r, $c, $side, $dirs) {
    $moves = [];
    foreach ($dirs as $d) {
        $nr = $r; $nc = $c;
        while (true) {
            $nr += $d[0]; $nc += $d[1];
            if (!validCell($nr, $nc)) break;
            if (empty($b[$nr][$nc])) $moves[] = [$nr, $nc];
            else {
                if (substr($b[$nr][$nc], 0, 1) != $side)
                    $moves[] = [$nr, $nc];
                break;
            }
        }
    }
    return $moves;
}

function getLegalMoves($b, $r, $c, $turn) {
    if (!isset($b[$r][$c]) || empty($b[$r][$c])) return [];
    $piece = $b[$r][$c];
    $side = substr($piece, 0, 1);
    $ptype = substr($piece, 1, 1);
    $moves = [];

    if ($ptype == 'P') {
        $dr = ($side == 'w') ? -1 : 1;
        if (validCell($r + $dr, $c) && $b[$r + $dr][$c] == "")
            $moves[] = [$r + $dr, $c];
        if (($side == 'w' && $r == 6) || ($side == 'b' && $r == 1)) {
            if ($b[$r + $dr][$c] == "" && $b[$r + 2 * $dr][$c] == "")
                $moves[] = [$r + 2 * $dr, $c];
        }
        foreach ([$c - 1, $c + 1] as $nc) {
            if (validCell($r + $dr, $nc) && !empty($b[$r + $dr][$nc]) && substr($b[$r + $dr][$nc], 0, 1) != $side)
                $moves[] = [$r + $dr, $nc];
        }
    } elseif ($ptype == 'N') {
        foreach ([[2,1],[2,-1],[-2,1],[-2,-1],[1,2],[1,-2],[-1,2],[-1,-2]] as $d) {
            $nr = $r + $d[0]; $nc = $c + $d[1];
            if (validCell($nr, $nc) && (empty($b[$nr][$nc]) || substr($b[$nr][$nc], 0, 1) != $side))
                $moves[] = [$nr, $nc];
        }
    } elseif ($ptype == 'B') {
        $dirs = [[1,1],[1,-1],[-1,1],[-1,-1]];
        $moves = array_merge($moves, rayMoves($b, $r, $c, $side, $dirs));
    } elseif ($ptype == 'R') {
        $dirs = [[1,0],[-1,0],[0,1],[0,-1]];
        $moves = array_merge($moves, rayMoves($b, $r, $c, $side, $dirs));
    } elseif ($ptype == 'Q') {
        $dirs = [[1,0],[-1,0],[0,1],[0,-1],[1,1],[1,-1],[-1,1],[-1,-1]];
        $moves = array_merge($moves, rayMoves($b, $r, $c, $side, $dirs));
    } elseif ($ptype == 'K') {
        foreach([[1,0],[-1,0],[0,1],[0,-1],[1,1],[1,-1],[-1,1],[-1,-1]] as $d) {
            $nr = $r + $d[0]; $nc = $c + $d[1];
            if (validCell($nr, $nc) && (empty($b[$nr][$nc]) || substr($b[$nr][$nc], 0, 1) != $side))
                $moves[] = [$nr, $nc];
        }
    }
    return $moves;
}

function pieceSVG($p) {
    $map = [
        "wP"=>'<svg width="32" height="32"><text x="8" y="25" font-size="26">&#9817;</text></svg>',
        "wN"=>'<svg width="32" height="32"><text x="8" y="25" font-size="26">&#9816;</text></svg>',
        "wB"=>'<svg width="32" height="32"><text x="8" y="25" font-size="26">&#9815;</text></svg>',
        "wR"=>'<svg width="32" height="32"><text x="8" y="25" font-size="26">&#9814;</text></svg>',
        "wQ"=>'<svg width="32" height="32"><text x="8" y="25" font-size="26">&#9813;</text></svg>',
        "wK"=>'<svg width="32" height="32"><text x="8" y="25" font-size="26">&#9812;</text></svg>',
        "bP"=>'<svg width="32" height="32"><text x="8" y="25" font-size="26">&#9823;</text></svg>',
        "bN"=>'<svg width="32" height="32"><text x="8" y="25" font-size="26">&#9822;</text></svg>',
        "bB"=>'<svg width="32" height="32"><text x="8" y="25" font-size="26">&#9821;</text></svg>',
        "bR"=>'<svg width="32" height="32"><text x="8" y="25" font-size="26">&#9820;</text></svg>',
        "bQ"=>'<svg width="32" height="32"><text x="8" y="25" font-size="26">&#9819;</text></svg>',
        "bK"=>'<svg width="32" height="32"><text x="8" y="25" font-size="26">&#9818;</text></svg>',
    ];
    return $map[$p] ?? '';
}

// === ХОД КОМПЬЮТЕРА ===
function makeComputerMove(&$board, &$captured_w, &$captured_b, &$game_over, &$winner) {
    // Собираем все возможные ходы для чёрных
    $allMoves = [];
    for ($r = 0; $r < 8; $r++) {
        for ($c = 0; $c < 8; $c++) {
            $piece = $board[$r][$c];
            if (!empty($piece) && substr($piece, 0, 1) === 'b') {
                $moves = getLegalMoves($board, $r, $c, 'b');
                foreach ($moves as $move) {
                    $allMoves[] = ['from' => [$r, $c], 'to' => $move];
                }
            }
        }
    }

    if (empty($allMoves)) {
        return; // нет ходов
    }

    // Выбираем случайный ход
    $chosen = $allMoves[array_rand($allMoves)];
    $from = $chosen['from'];
    $to = $chosen['to'];

    $piece = $board[$from[0]][$from[1]];
    $target = $board[$to[0]][$to[1]];

    // Проверка: съеден ли король?
    if (!empty($target) && substr($target, 1, 1) === 'K') {
        $game_over = true;
        $winner = 'b'; // компьютер победил
    }

    if (!empty($target)) {
        $captured_b[] = $target;
    }

    $board[$to[0]][$to[1]] = $piece;
    $board[$from[0]][$from[1]] = "";
}

// === ОБРАБОТКА ХОДА ИГРОКА ===
if (isset($_POST['square']) && empty($_SESSION['game_over'])) {
    list($r, $c) = explode(',', $_POST['square']);
    $r = (int)$r; $c = (int)$c;
    $board = &$_SESSION['board'];
    $selected = $_SESSION['selected'];
    $turn = $_SESSION['turn']; // всегда 'w' при ходе игрока

    if ($selected && $selected[0] == $r && $selected[1] == $c) {
        $_SESSION['selected'] = null;
        $_SESSION['moves'] = [];
    } elseif (!$selected && !empty($board[$r][$c]) && substr($board[$r][$c], 0, 1) == $turn) {
        $_SESSION['selected'] = [$r, $c];
        $_SESSION['moves'] = getLegalMoves($board, $r, $c, $turn);
    } elseif ($selected) {
        $moves = $_SESSION['moves'];
        foreach ($moves as $move) {
            if ($move[0] == $r && $move[1] == $c) {
                $from = $selected;
                $to = [$r, $c];
                $piece = $board[$from[0]][$from[1]];
                $target = $board[$r][$c];

                if (!empty($target)) {
                    if (substr($target, 1, 1) === 'K') {
                        $_SESSION['game_over'] = true;
                        $_SESSION['winner'] = 'w';

                        // Игрок победил — +10 рейтинга
                        $newRating = $_SESSION['rating'] + 10;
                        $stmt = $pdo->prepare("UPDATE users SET rating = ? WHERE id = ?");
                        $stmt->execute([$newRating, $_SESSION['user_id']]);
                        $_SESSION['rating'] = $newRating;

                        // Обновляем топ
                        $topPlayers = $pdo->query("SELECT username, rating FROM users ORDER BY rating DESC LIMIT 10")->fetchAll();
                    }
                    $_SESSION['captured_w'][] = $target;
                }

                $board[$to[0]][$to[1]] = $piece;
                $board[$from[0]][$from[1]] = "";
                $_SESSION['selected'] = null;
                $_SESSION['moves'] = [];

                // Если игра не окончена — ход компьютера
                if (empty($_SESSION['game_over'])) {
                    makeComputerMove(
                        $_SESSION['board'],
                        $_SESSION['captured_w'],
                        $_SESSION['captured_b'],
                        $_SESSION['game_over'],
                        $_SESSION['winner']
                    );

                    // Если после хода компьютера игра окончена — обновляем топ (но рейтинг не меняем)
                    if ($_SESSION['game_over'] && $_SESSION['winner'] === 'b') {
                        $topPlayers = $pdo->query("SELECT username, rating FROM users ORDER BY rating DESC LIMIT 10")->fetchAll();
                    }
                }
                break;
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Шахматы</title>
    <style>
        body::before {
            content: "";
            position: fixed;
            top: 0; left: 0;
            width: 100%; height: 100%;
            background-color: #e0e7ff;
            background-image:
                linear-gradient(45deg, #d0d8ff 25%, transparent 25%),
                linear-gradient(-45deg, #d0d8ff 25%, transparent 25%),
                linear-gradient(45deg, transparent 75%, #d0d8ff 75%),
                linear-gradient(-45deg, transparent 75%, #d0d8ff 75%);
            background-size: 100px 100px;
            background-position: 0 0, 0 50px, 50px -50px, -50px 0;
            z-index: -1;
            opacity: 0.6;
        }
        body {
            min-height: 100vh;
            font-family: 'Segoe UI', Arial, sans-serif;
            margin: 0;
            position: relative;
        }
        .container {
            max-width: 1000px;
            margin: 30px auto;
            padding: 32px;
            background: #fff;
            border-radius: 32px;
            box-shadow: 0 0 30px #816bff55;
            position: relative;
            z-index: 2;
            display: flex;
            gap: 30px;
        }
        .game-area { flex: 1; }
        .rating-panel {
            width: 280px;
            background: #f9faff;
            border-radius: 20px;
            padding: 20px;
            border: 2px solid #c1cdfc;
            box-shadow: 0 4px 12px rgba(129,107,255,0.1);
        }
        .rating-panel h2 {
            margin-top: 0;
            color: #2942e4;
            text-align: center;
            font-size: 1.4rem;
        }
        .rating-list {
            list-style: none;
            padding: 0;
            margin: 0;
        }
        .rating-item {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px solid #e6e9ff;
        }
        .rating-item:last-child {
            border-bottom: none;
        }
        .rating-rank {
            font-weight: bold;
            color: #555;
            width: 24px;
        }
        .rating-name {
            flex: 1;
            padding: 0 8px;
            color: #222;
        }
        .rating-score {
            font-weight: bold;
            color: #2942e4;
        }
        h1 {
            text-align: center;
            font-size: 2.5rem;
            margin: 0 0 18px 0;
        }
        .toprow {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        .turn {
            font-size: 1.09rem;
            margin-left: 8px;
        }
        .turn .black { color:#2942e4;text-decoration:underline;font-weight:600;}
        .turn .white { color:#2942e4;text-decoration:underline;font-weight:600;}
        .newgamebtn {
            background: linear-gradient(90deg, #816bff 0%, #a6befc 100%);
            color: #fff;
            border-radius: 22px;
            border: none;
            padding: 10px 34px;
            font-size: 1rem;
            font-weight:500;
            cursor: pointer;
            box-shadow:0 3px 14px #816bff25;
            transition: background 0.2s;
        }
        .newgamebtn:hover {
            background: linear-gradient(90deg, #a6befc 0%, #816bff 100%);
        }
        .logout {
            color: #816bff;
            text-decoration: none;
            font-size: 0.95rem;
            margin-left: 12px;
        }
        .chessboard {
            margin: 0 auto 19px auto;
            border: 3px solid #2a2645;
            border-radius: 14px;
            width: 440px; height: 440px;
            box-shadow:0 7px 24px #816bff25;
            display: grid;
            grid-template-rows: repeat(8,1fr);
            grid-template-columns: repeat(8,1fr);
            position: relative;
        }
        .square {
            width: 55px; height: 55px;
            display: flex; align-items: center; justify-content: center;
            position:relative;
            font-size:0;
        }
        .sq-white { background: #c1cdfc; }
        .sq-brown { background:rgba(36, 15, 157, 0.45) ; }
        .square.selected, .square.move {
            box-shadow: 0 0 0 3px #816bff, 0 0 0 7px #fff3;
        }
        .square.move {
            background: radial-gradient(circle, #c1cdfc 48%,rgba(36, 15, 157) 99%);
        }
        .coords-row, .coords-col {
            position: absolute;
            font-size: 13px; color:#47362a;
            font-weight:600;
        }
        .coords-row {left:-24px;margin-top:15px;}
        .coords-col {bottom:-21px;margin-left:17px;}
        .captured-blocks {
            display:flex;
            gap:14px;
            margin-top:32px;
        }
        .captured-side {
            background:#f9faff;
            border-radius:16px;
            flex:1;
            padding:10px 20px;
            min-height:54px;
            border:2px dashed #c1cdfc;
        }
        .captured-title {
            font-size:1rem;
            color:#444;
            font-weight:500;
            margin-bottom:6px;
        }
        .captured-pieces {
            display:flex;
            flex-wrap:wrap;
            gap:8px;
            min-height:34px;
        }
        .game-over {
            text-align: center;
            margin-top: 20px;
            font-size: 1.4rem;
            color: #816bff;
            font-weight: bold;
        }
    </style>
</head>
<body>
<div class="container">
    <div class="game-area">
        <h1>Шахматы</h1>
        <div class="toprow">
            <div>
                <div class="turn">
                    Ход:
                    <?php if ($_SESSION['turn']=='w' && !$_SESSION['game_over']): ?>
                        <span class="white">Белые (вы)</span>
                    <?php else: ?>
                        <span class="black">Чёрные (компьютер)</span>
                    <?php endif; ?>
                </div>
                <div style="margin-top:8px;">
                    Игрок: <strong><?= htmlspecialchars($_SESSION['user']) ?></strong>
                    (Рейтинг: <strong><?= (int)$_SESSION['rating'] ?></strong>)
                    <a href="index.php?logout=1" class="logout">Выйти</a>
                </div>
            </div>
            <form method="post"><button class="newgamebtn" type="submit" name="newgame">Новая игра</button></form>
        </div>

        <form method="post">
        <div class="chessboard">
            <?php
            $board = $_SESSION['board'];
            $selected = $_SESSION['selected'];
            $moves = $_SESSION['moves'];
            $game_over = !empty($_SESSION['game_over']);
            foreach (range(0,7) as $r) {
                foreach (range(0,7) as $c) {
                    $iswhite = ($r+$c)%2==0;
                    $sclass = ($iswhite)?'sq-white':'sq-brown';
                    $is_selected = ($selected&&$selected[0]==$r&&$selected[1]==$c);
                    $is_move = in_array([$r,$c], $moves);
                    $disabled = ($game_over || $_SESSION['turn'] !== 'w') ? 'disabled' : '';
                    echo '<button class="square '.$sclass.($is_selected?' selected':'').($is_move?' move':'').'" ';
                    echo 'name="square" value="'.$r.','.$c.'" tabindex="-1" '.$disabled.'>';
                    if (!empty($board[$r][$c])) echo pieceSVG($board[$r][$c]);
                    echo '</button>';
                }
            }
            foreach (range(0,7) as $r)
                echo '<span class="coords-row" style="top:'.($r*55+9).'px;">'.(8-$r).'</span>';
            foreach (range(0,7) as $c)
                echo '<span class="coords-col" style="left:'.($c*55+7).'px;">'.chr(97+$c).'</span>';
            ?>
        </div>
        </form>

        <?php if (!empty($_SESSION['game_over']) && $_SESSION['winner'] !== 'w'): ?>
            <div class="game-over">
                💀 Компьютер победил. Попробуйте снова!
            </div>
        <?php endif; ?>

        <div class="captured-blocks">
            <div class="captured-side">
                <div class="captured-title">Взятые вами:</div>
                <div class="captured-pieces">
                    <?php foreach ($_SESSION['captured_w'] as $f) echo pieceSVG($f);?>
                </div>
            </div>
            <div class="captured-side">
                <div class="captured-title">Взятые компьютером:</div>
                <div class="captured-pieces">
                    <?php foreach ($_SESSION['captured_b'] as $f) echo pieceSVG($f);?>
                </div>
            </div>
        </div>
    </div>

    <!-- Рейтинг справа -->
    <div class="rating-panel">
        <h2>🏆 Рейтинг</h2>
        <ul class="rating-list">
            <?php foreach ($topPlayers as $i => $player): ?>
                <li class="rating-item">
                    <span class="rating-rank"><?= $i + 1 ?>.</span>
                    <span class="rating-name"><?= htmlspecialchars($player['username']) ?></span>
                    <span class="rating-score"><?= (int)$player['rating'] ?></span>
                </li>
            <?php endforeach; ?>
            <?php if (empty($topPlayers)): ?>
                <li class="rating-item"><span style="color:#888;">Нет данных</span></li>
            <?php endif; ?>
        </ul>
    </div>
</div>

<?php
if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: login.php");
    exit;
}
?>

<!-- Победный модал с фейерверком -->
<?php if (!empty($_SESSION['game_over']) && $_SESSION['winner'] === 'w'): ?>
<div id="victory-modal">
    <div class="firework-container"></div>
    <div class="victory-text">🎉 Вы победили!</div>
</div>

<style>
#victory-modal {
    position: fixed;
    top: 0; left: 0;
    width: 100vw; height: 100vh;
    /* Изменено: прозрачный фон */
    background: transparent;
    display: flex;
    justify-content: center;
    align-items: center;
    z-index: 1000;
    opacity: 0;
    pointer-events: none;
    animation: fadeInModal 0.8s forwards;
}

.victory-text {
    font-size: 4.2rem;
    font-weight: 800;
    color: #5a3bff;
    text-align: center;
    text-shadow: 
        0 0 10px rgba(255,255,255,0.9),
        0 0 20px rgba(138, 43, 226, 0.6),
        0 0 40px rgba(106, 90, 205, 0.5);
    z-index: 1002;
    animation: bounceIn 1s cubic-bezier(0.175, 0.885, 0.32, 1.275);
    font-family: 'Segoe UI', Arial, sans-serif;
    letter-spacing: 1px;
}

.firework-container {
    position: absolute;
    top: 0; left: 0;
    width: 100%; height: 100%;
    pointer-events: none;
    z-index: 1001;
}

.firework {
    position: absolute;
    width: 12px;
    height: 12px;
    border-radius: 50%;
    opacity: 0;
    box-shadow: 0 0 8px currentColor;
}

@keyframes fadeInModal {
    to { opacity: 1; pointer-events: all; }
}

@keyframes bounceIn {
    0% { transform: scale(0.2); opacity: 0; }
    50% { transform: scale(1.2); }
    70% { transform: scale(0.95); }
    100% { transform: scale(1); opacity: 1; }
}

@keyframes fireworkBurst {
    0% {
        transform: translate(0, 0);
        opacity: 1;
        width: 12px;
        height: 12px;
        box-shadow: 0 0 15px currentColor;
    }
    100% {
        transform: translate(var(--tx), var(--ty));
        opacity: 0;
        width: 4px;
        height: 4px;
        box-shadow: 0 0 30px 8px currentColor;
    }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const container = document.querySelector('.firework-container');
    // Больше цветов и ярче
    const colors = ['#ff00cc', '#00ccff', '#ff6600', '#00ff99', '#ff3366', '#6633ff', '#ffff00', '#00ff00'];

    // Создаём 8 крупных фейерверков
    for (let i = 0; i < 8; i++) {
        setTimeout(() => {
            const firework = document.createElement('div');
            firework.classList.add('firework');
            firework.style.left = '50%';
            firework.style.top = '50%';
            const color = colors[Math.floor(Math.random() * colors.length)];
            firework.style.color = color; // используем color для box-shadow

            // Случайное направление и расстояние (до 300px)
            const angle = Math.random() * Math.PI * 2;
            const distance = 150 + Math.random() * 250;
            const tx = Math.cos(angle) * distance;
            const ty = Math.sin(angle) * distance;

            firework.style.setProperty('--tx', tx + 'px');
            firework.style.setProperty('--ty', ty + 'px');

            container.appendChild(firework);

            // Длительная и плавная анимация
            firework.style.animation = `fireworkBurst ${1.0 + Math.random() * 0.8}s forwards`;

            // Удаляем после анимации
            setTimeout(() => {
                if (firework.parentNode) firework.parentNode.removeChild(firework);
            }, 2000);
        }, i * 250); // чаще запускаем
    }

    // Закрыть через 6 секунд
    setTimeout(() => {
        const modal = document.getElementById('victory-modal');
        if (modal) {
            modal.style.animation = 'fadeInModal 0.2s reverse forwards';
            setTimeout(() => {
                modal.remove();
            }, 200);
        }
    }, 2000);
});

</script>
<?php endif; ?>

</body>
</html>

