<?php
// PHP 8.x Chess Application
// Seluruh logika permainan dan status (state) dikelola di sisi server.
session_start();

// --- Definisi Bidak dan Papan ---
const UNICODE_PIECES = [
    'wp' => '♙', 'wr' => '♖', 'wn' => '♘', 'wb' => '♗', 'wq' => '♕', 'wk' => '♔',
    'bp' => '♟', 'br' => '♜', 'bn' => '♞', 'bb' => '♝', 'bq' => '♛', 'bk' => '♚'
];

const INITIAL_BOARD = [
    ['br', 'bn', 'bb', 'bq', 'bk', 'bb', 'bn', 'br'],
    ['bp', 'bp', 'bp', 'bp', 'bp', 'bp', 'bp', 'bp'],
    ['', '', '', '', '', '', '', ''],
    ['', '', '', '', '', '', '', ''],
    ['', '', '', '', '', '', '', ''],
    ['', '', '', '', '', '', '', ''],
    ['wp', 'wp', 'wp', 'wp', 'wp', 'wp', 'wp', 'wp'],
    ['wr', 'wn', 'wb', 'wq', 'wk', 'wb', 'wn', 'wr']
];

// --- Fungsi Inisialisasi Game ---
function initGame() {
    $_SESSION['board'] = INITIAL_BOARD;
    $_SESSION['turn'] = 'w';
    $_SESSION['selected'] = null;
    $_SESSION['legal_moves'] = [];
    $_SESSION['lastMove'] = null;
    $_SESSION['history'] = [];
    $_SESSION['flags'] = ['wK' => true, 'wQ' => true, 'bK' => true, 'bQ' => true];
    $_SESSION['captured'] = ['w' => [], 'b' => []];
    $_SESSION['info'] = '';
    $_SESSION['isFlipped'] = false;
    $_SESSION['awaiting_promotion'] = null; // Menyimpan {r, c, from_r, from_c, moving, capturedOnSquare, special, enPassantCapture}
}

// --- Fungsi Pembantu (Helpers) ---
function inBounds(int $r, int $c): bool {
    return $r >= 0 && $r < 8 && $c >= 0 && $c < 8;
}

function colorOf(?string $p): ?string {
    return ($p) ? $p[0] : null;
}

function findKing(array $bstate, string $color): ?array {
    for ($r = 0; $r < 8; $r++) {
        for ($c = 0; $c < 8; $c++) {
            if ($bstate[$r][$c] === $color . 'k') {
                return [$r, $c];
            }
        }
    }
    return null;
}

// --- Logika Validasi Gerakan (Porting dari JS) ---

function pseudoMovesAtBoard(array $bstate, int $r, int $c): array {
    $p = $bstate[$r][$c] ?? null;
    if (!$p) return [];
    $color = $p[0];
    $type = $p[1];
    $moves = [];

    if ($type === 'p') {
        $dir = $color === 'w' ? -1 : 1;
        foreach ([-1, 1] as $dc) {
            $nr = $r + $dir;
            $nc = $c + $dc;
            if (inBounds($nr, $nc)) $moves[] = [$nr, $nc];
        }
    } elseif ($type === 'n') {
        $del = [[-2, -1], [-2, 1], [-1, -2], [-1, 2], [1, -2], [1, 2], [2, -1], [2, 1]];
        foreach ($del as [$dr, $dc]) {
            $nr = $r + $dr;
            $nc = $c + $dc;
            if (inBounds($nr, $nc)) $moves[] = [$nr, $nc];
        }
    } elseif (in_array($type, ['b', 'r', 'q'])) {
        $dirs = [];
        if (in_array($type, ['b', 'q'])) $dirs = [...$dirs, [-1, -1], [-1, 1], [1, -1], [1, 1]];
        if (in_array($type, ['r', 'q'])) $dirs = [...$dirs, [-1, 0], [1, 0], [0, -1], [0, 1]];
        foreach ($dirs as [$dr, $dc]) {
            $nr = $r + $dr;
            $nc = $c + $dc;
            while (inBounds($nr, $nc)) {
                $moves[] = [$nr, $nc];
                if ($bstate[$nr][$nc] !== '') break;
                $nr += $dr;
                $nc += $dc;
            }
        }
    } elseif ($type === 'k') {
        foreach ([-1, 0, 1] as $dr) {
            foreach ([-1, 0, 1] as $dc) {
                if ($dr === 0 && $dc === 0) continue;
                $nr = $r + $dr;
                $nc = $c + $dc;
                if (inBounds($nr, $nc)) $moves[] = [$nr, $nc];
            }
        }
    }
    return $moves;
}

function isSquareAttacked(array $square, string $byColor, array $bstate): bool {
    [$sr, $sc] = $square;
    for ($r = 0; $r < 8; $r++) {
        for ($c = 0; $c < 8; $c++) {
            $p = $bstate[$r][$c] ?? null;
            if (!$p || colorOf($p) !== $byColor) continue;
            
            $moves = pseudoMovesAtBoard($bstate, $r, $c);
            foreach ($moves as $m) {
                if ($m[0] === $sr && $m[1] === $sc) {
                    if ($p[1] === 'p') {
                        $dir = $byColor === 'w' ? -1 : 1;
                        if ($r + $dir === $sr && ($c - 1 === $sc || $c + 1 === $sc)) return true;
                    } else {
                        return true;
                    }
                }
            }
        }
    }
    return false;
}

function isInCheck(array $bstate, string $color): bool {
    $king = findKing($bstate, $color);
    if (!$king) return true;
    $enemy = $color === 'w' ? 'b' : 'w';
    return isSquareAttacked($king, $enemy, $bstate);
}

function pseudoMovesAt(int $r, int $c, array $bstate, ?array $lastMove, array $flags): array {
    $p = $bstate[$r][$c] ?? null;
    if (!$p) return [];
    $color = $p[0];
    $type = $p[1];
    $moves = [];
    $enemy = $color === 'w' ? 'b' : 'w';

    if ($type === 'p') {
        $dir = $color === 'w' ? -1 : 1;
        if (inBounds($r + $dir, $c) && $bstate[$r + $dir][$c] === '') $moves[] = [$r + $dir, $c];
        if (($color === 'w' && $r === 6) || ($color === 'b' && $r === 1)) {
            if ($bstate[$r + $dir][$c] === '' && $bstate[$r + 2 * $dir][$c] === '') $moves[] = [$r + 2 * $dir, $c];
        }
        foreach ([-1, 1] as $dc) {
            $nr = $r + $dir;
            $nc = $c + $dc;
            if (inBounds($nr, $nc) && $bstate[$nr][$nc] && colorOf($bstate[$nr][$nc]) !== $color) $moves[] = [$nr, $nc];
        }
        if ($lastMove && ($lastMove['piece'][1] ?? null) === 'p') {
            if (abs($lastMove['from']['r'] - $lastMove['to']['r']) === 2 && $lastMove['to']['r'] === $r && abs($lastMove['to']['c'] - $c) === 1) {
                $moves[] = [$r + $dir, $lastMove['to']['c']];
            }
        }
    } elseif ($type === 'n') {
        $del = [[-2, -1], [-2, 1], [-1, -2], [-1, 2], [1, -2], [1, 2], [2, -1], [2, 1]];
        foreach ($del as [$dr, $dc]) {
            $nr = $r + $dr;
            $nc = $c + $dc;
            if (inBounds($nr, $nc) && colorOf($bstate[$nr][$nc] ?? null) !== $color) $moves[] = [$nr, $nc];
        }
    } elseif (in_array($type, ['b', 'r', 'q'])) {
        $dirs = [];
        if (in_array($type, ['b', 'q'])) $dirs = [...$dirs, [-1, -1], [-1, 1], [1, -1], [1, 1]];
        if (in_array($type, ['r', 'q'])) $dirs = [...$dirs, [-1, 0], [1, 0], [0, -1], [0, 1]];
        foreach ($dirs as [$dr, $dc]) {
            $nr = $r + $dr;
            $nc = $c + $dc;
            while (inBounds($nr, $nc)) {
                if ($bstate[$nr][$nc] === '') {
                    $moves[] = [$nr, $nc];
                } else {
                    if (colorOf($bstate[$nr][$nc]) !== $color) $moves[] = [$nr, $nc];
                    break;
                }
                $nr += $dr;
                $nc += $dc;
            }
        }
    } elseif ($type === 'k') {
        foreach ([-1, 0, 1] as $dr) {
            foreach ([-1, 0, 1] as $dc) {
                if ($dr === 0 && $dc === 0) continue;
                $nr = $r + $dr;
                $nc = $c + $dc;
                if (inBounds($nr, $nc) && colorOf($bstate[$nr][$nc] ?? null) !== $color) $moves[] = [$nr, $nc];
            }
        }
        if ($color === 'w' && $r === 7 && $c === 4) {
            if ($flags['wK'] && $bstate[7][5] === '' && $bstate[7][6] === '' && !isSquareAttacked([7, 4], $enemy, $bstate) && !isSquareAttacked([7, 5], $enemy, $bstate) && !isSquareAttacked([7, 6], $enemy, $bstate)) $moves[] = [7, 6];
            if ($flags['wQ'] && $bstate[7][1] === '' && $bstate[7][2] === '' && $bstate[7][3] === '' && !isSquareAttacked([7, 4], $enemy, $bstate) && !isSquareAttacked([7, 3], $enemy, $bstate) && !isSquareAttacked([7, 2], $enemy, $bstate)) $moves[] = [7, 2];
        }
        if ($color === 'b' && $r === 0 && $c === 4) {
            if ($flags['bK'] && $bstate[0][5] === '' && $bstate[0][6] === '' && !isSquareAttacked([0, 4], $enemy, $bstate) && !isSquareAttacked([0, 5], $enemy, $bstate) && !isSquareAttacked([0, 6], $enemy, $bstate)) $moves[] = [0, 6];
            if ($flags['bQ'] && $bstate[0][1] === '' && $bstate[0][2] === '' && $bstate[0][3] === '' && !isSquareAttacked([0, 4], $enemy, $bstate) && !isSquareAttacked([0, 3], $enemy, $bstate) && !isSquareAttacked([0, 2], $enemy, $bstate)) $moves[] = [0, 2];
        }
    }
    return $moves;
}

function legalMovesAt(int $r, int $c, array $bstate, string $turn, ?array $lastMove, array $flags): array {
    $p = $bstate[$r][$c] ?? null;
    if (!$p) return [];
    $pseudo = pseudoMovesAt($r, $c, $bstate, $lastMove, $flags);
    $legal = [];
    $color = $p[0];

    foreach ($pseudo as [$nr, $nc]) {
        $b2 = $bstate; // Salin papan
        // --- Simulasi ---
        if ($p[1] === 'p' && $nc !== $c && $b2[$nr][$nc] === '') $b2[$r][$nc] = ''; // En-passant
        if ($p[1] === 'k' && abs($nc - $c) === 2) { // Castling
            if ($nc === 6) { $b2[$nr][5] = $b2[$nr][7]; $b2[$nr][7] = ''; }
            else { $b2[$nr][3] = $b2[$nr][0]; $b2[$nr][0] = ''; }
        }
        $b2[$nr][$nc] = $b2[$r][$c];
        $b2[$r][$c] = '';
        if (($b2[$nr][$nc][1] ?? null) === 'p' && ($nr === 0 || $nr === 7)) $b2[$nr][$nc] = $color . 'q'; // Auto-promote for check
        // --- Akhir Simulasi ---

        if (!isInCheck($b2, $color)) {
            $legal[] = [$nr, $nc];
        }
    }
    return $legal;
}

function hasAnyLegalMoves(string $color, array $bstate, ?array $lastMove, array $flags): bool {
    for ($r = 0; $r < 8; $r++) {
        for ($c = 0; $c < 8; $c++) {
            if (($bstate[$r][$c] ?? null) && colorOf($bstate[$r][$c]) === $color) {
                if (count(legalMovesAt($r, $c, $bstate, $color, $lastMove, $flags)) > 0) {
                    return true;
                }
            }
        }
    }
    return false;
}

// --- Logika Aksi (Controller) ---

if (!isset($_SESSION['board'])) {
    initGame();
}

$action = $_GET['action'] ?? null;
$r_get = isset($_GET['r']) ? (int)$_GET['r'] : null;
$c_get = isset($_GET['c']) ? (int)$_GET['c'] : null;
$promo_piece = $_GET['piece'] ?? null;

$turn = $_SESSION['turn'];
$board = $_SESSION['board'];
$flags = $_SESSION['flags'];
$lastMove = $_SESSION['lastMove'];

if ($action) {
    if ($action === 'reset') {
        initGame();
    } elseif ($action === 'flip') {
        $_SESSION['isFlipped'] = !($_SESSION['isFlipped'] ?? false);
    } elseif ($action === 'undo' && !$_SESSION['awaiting_promotion']) {
        if (count($_SESSION['history']) > 0) {
            $lastState = array_pop($_SESSION['history']);
            $_SESSION['board'] = $lastState['board'];
            $_SESSION['turn'] = $lastState['turn'];
            $_SESSION['flags'] = $lastState['flags'];
            $_SESSION['lastMove'] = $lastState['lastMove'];
            $_SESSION['captured'] = $lastState['captured'];
            $_SESSION['info'] = '';
            $_SESSION['selected'] = null;
            $_SESSION['legal_moves'] = [];
        }
    } elseif ($action === 'promote' && $_SESSION['awaiting_promotion']) {
        // Lanjutkan langkah promosi
        $promoData = $_SESSION['awaiting_promotion'];
        $board = $_SESSION['board'];
        $board[$promoData['r']][$promoData['c']] = $turn . $promo_piece; // Terapkan bidak yg dipilih
        $_SESSION['board'] = $board;
        
        // Finalisasi langkah (dari onCellClick)
        $capturedOnSquare = $promoData['capturedOnSquare'];
        $enPassantCapture = $promoData['enPassantCapture'];
        
        if ($capturedOnSquare) {
            $_SESSION['captured'][$turn][] = $capturedOnSquare;
        } elseif ($enPassantCapture) {
            $_SESSION['captured'][$turn][] = $enPassantCapture;
        }
        
        $_SESSION['lastMove'] = [
            'from' => ['r' => $promoData['from_r'], 'c' => $promoData['from_c']],
            'to' => ['r' => $promoData['r'], 'c' => $promoData['c']],
            'piece' => $promoData['moving'],
            'captured' => $capturedOnSquare ?? $enPassantCapture,
            'special' => $promoData['special']
        ];
        
        $_SESSION['turn'] = $turn === 'w' ? 'b' : 'w';
        $_SESSION['awaiting_promotion'] = null;
        $_SESSION['info'] = ''; // Info skak akan dihitung ulang di bawah
        
    } elseif ($action === 'click' && $r_get !== null && $c_get !== null && !$_SESSION['awaiting_promotion']) {
        // Logika klik sel
        $p = $board[$r_get][$c_get] ?? null;
        $selected = $_SESSION['selected'];
        
        if (!$selected) {
            if ($p && colorOf($p) === $turn) {
                $legal = legalMovesAt($r_get, $c_get, $board, $turn, $lastMove, $flags);
                if (count($legal) > 0) {
                    $_SESSION['selected'] = ['r' => $r_get, 'c' => $c_get];
                    $_SESSION['legal_moves'] = $legal;
                } else {
                    $_SESSION['info'] = 'Bidak ini tidak punya langkah legal.';
                }
            }
        } else {
            // Cek apakah klik adalah langkah legal
            $isLegal = false;
            foreach ($_SESSION['legal_moves'] as $move) {
                if ($move[0] === $r_get && $move[1] === $c_get) {
                    $isLegal = true;
                    break;
                }
            }
            
            if ($isLegal) {
                // Simpan history
                $_SESSION['history'][] = [
                    'board' => $_SESSION['board'],
                    'turn' => $_SESSION['turn'],
                    'flags' => $_SESSION['flags'],
                    'lastMove' => $_SESSION['lastMove'],
                    'captured' => $_SESSION['captured']
                ];

                // Lakukan gerakan
                $fr = $selected['r'];
                $fc = $selected['c'];
                $tr = $r_get;
                $tc = $c_get;
                
                $moving = $board[$fr][$fc];
                $capturedOnSquare = $board[$tr][$tc];
                $enPassantCapture = null;
                $special = null;
                
                // En-passant
                if ($moving[1] === 'p' && $fc !== $tc && $board[$tr][$tc] === '') {
                    $capR = $fr; $capC = $tc;
                    $special = 'en-passant';
                    $enPassantCapture = $turn === 'w' ? 'bp' : 'wp';
                    $board[$capR][$capC] = '';
                }
                
                // Castling
                if ($moving[1] === 'k' && abs($tc - $fc) === 2) {
                    if ($tc === 6) { $board[$tr][5] = $board[$tr][7]; $board[$tr][7] = ''; $special = 'castle-k'; }
                    else { $board[$tr][3] = $board[$tr][0]; $board[$tr][0] = ''; $special = 'castle-q'; }
                }
                
                // Gerakan normal
                $board[$tr][$tc] = $board[$fr][$fc];
                $board[$fr][$fc] = '';
                
                // Update hak castling
                if ($moving === 'wk') { $flags['wK'] = false; $flags['wQ'] = false; }
                if ($moving === 'bk') { $flags['bK'] = false; $flags['bQ'] = false; }
                if ($fr === 7 && $fc === 0) $flags['wQ'] = false;
                if ($fr === 7 && $fc === 7) $flags['wK'] = false;
                if ($fr === 0 && $fc === 0) $flags['bQ'] = false;
                if ($fr === 0 && $fc === 7) $flags['bK'] = false;
                if ($tr === 7 && $tc === 0) $flags['wQ'] = false;
                if ($tr === 7 && $tc === 7) $flags['wK'] = false;
                if ($tr === 0 && $tc === 0) $flags['bQ'] = false;
                if ($tr === 0 && $tc === 7) $flags['bK'] = false;
                
                $_SESSION['board'] = $board;
                $_SESSION['flags'] = $flags;
                
                // Cek promosi
                if ($moving[1] === 'p' && ($tr === 0 || $tr === 7)) {
                    $_SESSION['awaiting_promotion'] = [
                        'r' => $tr, 'c' => $tc, 'from_r' => $fr, 'from_c' => $fc, 
                        'moving' => $moving, 'capturedOnSquare' => $capturedOnSquare, 
                        'special' => $special, 'enPassantCapture' => $enPassantCapture
                    ];
                } else {
                    // Finalisasi langkah (jika bukan promosi)
                    if ($capturedOnSquare) $_SESSION['captured'][$turn][] = $capturedOnSquare;
                    elseif ($enPassantCapture) $_SESSION['captured'][$turn][] = $enPassantCapture;
                    
                    $_SESSION['lastMove'] = [
                        'from' => ['r' => $fr, 'c' => $fc],
                        'to' => ['r' => $tr, 'c' => $tc],
                        'piece' => $moving,
                        'captured' => $capturedOnSquare ?? $enPassantCapture,
                        'special' => $special
                    ];
                    $_SESSION['turn'] = $turn === 'w' ? 'b' : 'w';
                }
                
                $_SESSION['selected'] = null;
                $_SESSION['legal_moves'] = [];
                $_SESSION['info'] = '';

            } elseif ($p && colorOf($p) === $turn) {
                // Ganti bidak yang dipilih
                $legal = legalMovesAt($r_get, $c_get, $board, $turn, $lastMove, $flags);
                if (count($legal) > 0) {
                    $_SESSION['selected'] = ['r' => $r_get, 'c' => $c_get];
                    $_SESSION['legal_moves'] = $legal;
                } else {
                    $_SESSION['info'] = 'Bidak ini tidak punya langkah legal.';
                    $_SESSION['selected'] = null;
                    $_SESSION['legal_moves'] = [];
                }
            } else {
                // Deselect
                $_SESSION['selected'] = null;
                $_SESSION['legal_moves'] = [];
                $_SESSION['info'] = '';
            }
        }
    }
    
    // Hitung ulang status skak/skakmat SETELAH langkah (jika tidak menunggu promosi)
    if (!$_SESSION['awaiting_promotion']) {
        $currentTurn = $_SESSION['turn'];
        $currentBoard = $_SESSION['board'];
        $currentFlags = $_SESSION['flags'];
        $currentLastMove = $_SESSION['lastMove'];
        
        $inCheck = isInCheck($currentBoard, $currentTurn);
        $hasMoves = hasAnyLegalMoves($currentTurn, $currentBoard, $currentLastMove, $currentFlags);
        
        if ($inCheck && !$hasMoves) {
            $_SESSION['info'] = 'Skakmat! ' . ($currentTurn === 'w' ? 'Hitam' : 'Putih') . ' menang.';
        } elseif ($inCheck) {
            $_SESSION['info'] = 'Skak untuk ' . ($currentTurn === 'w' ? 'Putih' : 'Hitam');
        } elseif (!$hasMoves) {
            $_SESSION['info'] = 'Stalemate (Seri)';
        } else {
            // Hapus info lama jika tidak ada status di atas
            if ($action !== 'click') $_SESSION['info'] = '';
        }
    }

    // Redirect untuk membersihkan parameter GET dan mencegah re-submit
    if ($action) { // MODIFIKASI: Dihapus ' !== 'flip' ' agar 'flip' juga redirect
        // MODIFIKASI: Gunakan strtok untuk mendapatkan URL tanpa query string
        $redirect_url = strtok($_SERVER["REQUEST_URI"], '?');
        header("Location: $redirect_url");
        exit;
    }
}

// --- Data untuk Render (View) ---
$board = $_SESSION['board'];
$turn = $_SESSION['turn'];
$info = $_SESSION['info'];
$flags = $_SESSION['flags'];
$lastMove = $_SESSION['lastMove'];
$selected = $_SESSION['selected'];
$highlights = $_SESSION['legal_moves'];
$captured = $_SESSION['captured'];
$isFlipped = $_SESSION['isFlipped'] ?? false;
$awaitingPromotion = $_SESSION['awaiting_promotion'];

// MODIFIKASI: Hapus $self_url, kita tidak membutuhkannya lagi
// $self_url = htmlspecialchars($_SERVER['PHP_SELF'], ENT_QUOTES, 'UTF-8');

function getPiece(string $p): string {
    return UNICODE_PIECES[$p] ?? '';
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Aplikasi Catur PHP</title>
  <style>
    :root{--light:#f0d9b5;--dark:#b58863}
    body{font-family:Inter,system-ui,Arial;margin:0;padding:12px;background:#f5f7fa; display: flex; justify-content: center;}
    h2{margin:0 0 12px}
    .app-container { width: 100%; max-width: 960px; }
    .app{display:flex;gap:20px;align-items:flex-start; flex-wrap: wrap;}
    #board{
      display:grid;
      grid-template-columns:repeat(8, clamp(40px, 11.5vw, 60px));
      grid-template-rows:repeat(8, clamp(40px, 11.5vw, 60px));
      box-shadow:0 6px 20px rgba(0,0,0,0.08);
      max-width: calc(8 * 60px);
      max-height: calc(8 * 60px);
      <?php if ($isFlipped): ?>
      transform: rotate(180deg);
      <?php endif; ?>
    }
    .cell{
      width: clamp(40px, 11.5vw, 60px);
      height: clamp(40px, 11.5vw, 60px);
      display:flex;align-items:center;justify-content:center;
      font-size: clamp(28px, 8vw, 34px);
      cursor:pointer;user-select:none;
      box-sizing: border-box;
      text-decoration: none;
      color: #111;
      <?php if ($isFlipped): ?>
      transform: rotate(180deg);
      <?php endif; ?>
    }
    .white{background:var(--light)}
    .black{background:var(--dark);}
    .selected{outline:3px solid #ff6b6b; outline-offset: -3px;}
    .highlight{outline:3px solid #ffd54f; outline-offset: -3px;}
    .ui{ width: 100%; max-width: 420px; flex-grow: 1; }
    .card{background:white;padding:12px;border-radius:8px;box-shadow:0 4px 12px rgba(0,0,0,0.06)}
    .controls { display: flex; gap: 8px; margin-top: 10px; }
    button, .button-link {
        padding:8px 10px;border-radius:6px;border:1px solid #ddd;background:#fff;
        cursor:pointer; font-family: inherit; font-size: 14px; text-decoration: none; color: black;
        display: inline-block; line-height: normal;
    }
    a.button-link:hover { background: #f4f4f4; }
    .moves{margin-top:8px;display:flex;gap:8px;flex-wrap:wrap}
    .captured-pieces { min-height: 32px; display: flex; flex-wrap: wrap; gap: 4px; font-size: 28px; line-height: 1; padding-top: 4px; color: #333; }
    .modal{position:fixed;inset:0;display:flex;align-items:center;justify-content:center;background:rgba(0,0,0,0.35); z-index: 100;}
    .modal-card{background:white;padding:18px;border-radius:10px;display:flex;flex-direction: column; gap:12px}
    #promoOptions { display: flex; gap: 10px; }
    .promo-piece{
        font-size:40px;padding:6px 12px;cursor:pointer;border-radius:8px;border:1px solid #eee;
        transition: background 0.2s; text-decoration: none; color: #111;
    }
    .promo-piece:hover { background: #f0f0f0; }
  </style>
</head>
<body>
  <div class="app-container">
    <h2>Aplikasi Catur (PHP Server-Side)</h2>
    <div class="app">
      <div id="board">
        <?php for ($r_idx = 0; $r_idx < 8; $r_idx++): ?>
            <?php for ($c_idx = 0; $c_idx < 8; $c_idx++): ?>
                <?php
                $r = $isFlipped ? 7 - $r_idx : $r_idx;
                $c = $isFlipped ? 7 - $c_idx : $c_idx;
                
                $p = $board[$r][$c];
                $piece = $p ? getPiece($p) : '';
                $class = ($r + $c) % 2 === 0 ? 'white' : 'black';
                
                if ($selected && $selected['r'] === $r && $selected['c'] === $c) {
                    $class .= ' selected';
                }
                foreach ($highlights as $move) {
                    if ($move[0] === $r && $move[1] === $c) {
                        $class .= ' highlight';
                        break;
                    }
                }
                ?>
                <!-- MODIFIKASI: Ganti href dengan link query string relatif -->
                <a class="cell <?php echo $class; ?>" href="?action=click&r=<?php echo $r; ?>&c=<?php echo $c; ?>">
                    <?php echo $piece; ?>
                </a>
            <?php endfor; ?>
        <?php endfor; ?>
      </div>

      <div class="ui">
        <div class="card">
          <div><strong>Giliran:</strong> <span id="turn"><?php echo $turn === 'w' ? 'Putih' : 'Hitam'; ?></span></div>
          <div id="info" style="margin-top:8px;color:#d32;font-weight:700; min-height: 1.2em;"><?php echo $info; ?></div>
          
          <div class="controls">
            <!-- MODIFIKASI: Ganti href dengan link query string relatif -->
            <a href="?action=undo" class="button-link <?php echo $awaitingPromotion ? 'disabled' : ''; ?>" <?php echo $awaitingPromotion ? 'aria-disabled="true" onclick="return false;"' : ''; ?>>Undo</a>
            <a href="?action=reset" class="button-link <?php echo $awaitingPromotion ? 'disabled' : ''; ?>" <?php echo $awaitingPromotion ? 'aria-disabled="true" onclick="return false;"' : ''; ?>>Reset</a>
            <a href="?action=flip" class="button-link">Balik Papan</a>
          </div>
          
          <div style="margin-top:12px"><strong>Game flags</strong>
            <div class="moves" id="flags">
                <?php
                $flagItems = [];
                if ($flags['wK']) $flagItems[] = 'W K-side';
                if ($flags['wQ']) $flagItems[] = 'W Q-side';
                if ($flags['bK']) $flagItems[] = 'B K-side';
                if ($flags['bQ']) $flagItems[] = 'B Q-side';
                // MODIFIKASI: Ganti String.fromCharCode() (JS) dengan chr() (PHP)
                $last = $lastMove ? (chr(97 + $lastMove['from']['c']) . (8 - $lastMove['from']['r'])) . '→' . (chr(97 + $lastMove['to']['c']) . (8 - $lastMove['to']['r'])) : '-';
                $flagItems[] = 'Last: ' . $last;
                ?>
                <?php foreach ($flagItems as $item): ?>
                    <div style="padding:6px 8px;border:1px solid #eee;border-radius:6px;font-size:12px;"><?php echo $item; ?></div>
                <?php endforeach; ?>
            </div>
          </div>
        </div>
        
        <div class="card" style="margin-top:12px;">
          <div><strong>Dimakan oleh Putih:</strong></div>
          <div class="captured-pieces">
            <?php 
            sort($captured['w']);
            foreach ($captured['w'] as $p) echo getPiece($p); 
            ?>
          </div>
          <div style="margin-top:8px;"><strong>Dimakan oleh Hitam:</strong></div>
          <div class="captured-pieces">
            <?php 
            sort($captured['b']);
            foreach ($captured['b'] as $p) echo getPiece($p); 
            ?>
          </div>
        </div>
      </div>
    </div>
  </div>

  <?php if ($awaitingPromotion): ?>
  <div id="promoModal" class="modal">
    <div class="modal-card">
      <div style="font-weight:700">Pilih promosi:</div>
      <div id="promoOptions">
        <?php foreach (['q', 'r', 'b', 'n'] as $promo): ?>
            <!-- MODIFIKASI: Ganti href dengan link query string relatif -->
            <a href="?action=promote&piece=<?php echo $promo; ?>" class="promo-piece">
                <?php echo getPiece($turn . $promo); ?>
            </a>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
  <?php endif; ?>

</body>
</html>