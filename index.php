<?php
/**
 * ORTO SYSTEM v10.0 - LOGIN FIX
 * 1. Corrección de nombre de Base de Datos.
 * 2. Login Resetable (Limpia errores anteriores).
 * 3. Validación de campos vacíos.
 */

session_start();

// --- 1. CONFIGURACIÓN DB (CORREGIDA) ---
 $db_host = 'sql302.infinityfree.com';
 $db_user = 'if0_40781830';
 $db_pass = 'zUSP5m2qizFp';
 $db_name = 'if0_40781830_orthocenter'; // Asegúrate que este nombre sea EXACTO al tuyo

 $conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
if ($conn->connect_error) { die("Error de conexión DB. Verifica el nombre: " . $conn->connect_error); }

// --- 2. BACKEND API ---
if (isset($_GET['api'])) {
    header('Content-Type: application/json');
    $action = $_GET['api'];
    $input = json_decode(file_get_contents('php://input'), true);

    function jsonOut($data) { echo json_encode($data); exit; }
    
    // Recuperar usuario actual
    $currentUser = isset($_SESSION['user']) ? $_SESSION['user'] : null;

    switch ($action) {
        case 'login':
            // Validar que vengan datos
            if(!isset($input['user']) || !isset($input['pass'])) {
                jsonOut(['status'=>'error', 'msg'=>'Faltan datos']);
            }
            
            $u = $conn->real_escape_string($input['user']);
            $p = $conn->real_escape_string($input['pass']);
            
            // Limpieza básica de cadenas para evitar errores SQL
            $u = trim($u);
            $p = trim($p);

            $res = $conn->query("SELECT * FROM users WHERE username='$u' AND password='$p'")->fetch_assoc();
            
            if ($res) {
                if ($res['active'] == 0) jsonOut(['status'=>'error', 'msg'=>'Cuenta Bloqueada']);
                if ($res['role'] !== 'dev' && $res['expiry'] < date('Y-m-d')) jsonOut(['status'=>'error', 'msg'=>'Membresía Vencida']);
                
                $_SESSION['user'] = $res;
                jsonOut(['status'=>'success', 'user'=>$res]);
            } else {
                // Mensaje genérico para no revelar si existe o no el usuario
                jsonOut(['status'=>'error', 'msg'=>'Usuario o contraseña incorrectos']);
            }
            break;

        case 'logout':
            session_unset();
            session_destroy();
            jsonOut(['status'=>'success']);
            break;

        case 'get_data':
            if (!$currentUser) jsonOut(['status'=>'error', 'msg'=>'No autorizado']);
            
            $data = [];
            // OBTENER DATOS
            $data['users'] = $conn->query("SELECT id, username, role, active, expiry FROM users")->fetch_all(MYSQLI_ASSOC);
            $data['settings'] = $conn->query("SELECT * FROM settings")->fetch_assoc();
            $data['patients'] = $conn->query("SELECT * FROM patients ORDER BY id DESC")->fetch_all(MYSQLI_ASSOC);
            $data['products'] = $conn->query("SELECT * FROM products")->fetch_all(MYSQLI_ASSOC);
            $data['appointments'] = $conn->query("SELECT * FROM appointments WHERE status='pending' ORDER BY appointment_datetime ASC")->fetch_all(MYSQLI_ASSOC);
            $data['rentals'] = $conn->query("SELECT * FROM rentals WHERE status='active'")->fetch_all(MYSQLI_ASSOC);
            $data['sales'] = $conn->query("SELECT * FROM sales ORDER BY sale_date DESC")->fetch_all(MYSQLI_ASSOC);

            // Alertas
            $alerts = [];
            $now = date('Y-m-d H:i:s');
            
            // Lógica de alertas
            foreach ($data['appointments'] as &$a) {
                $diff = (strtotime($a['appointment_datetime']) - strtotime($now)) / 60;
                if ($diff <= 10 && $diff > 5 && !$a['alerted_10']) { $alerts[] = ['msg'=>"Cita 10m: ".$a['patient_name'], 'type'=>'info']; $conn->query("UPDATE appointments SET alerted_10=1 WHERE id=".$a['id']); }
                if ($diff <= 5 && $diff > 2 && !$a['alerted_5']) { $alerts[] = ['msg'=>"Cita 5m: ".$a['patient_name'], 'type'=>'warning']; $conn->query("UPDATE appointments SET alerted_5=1 WHERE id=".$a['id']); }
                if ($diff <= 2 && $diff >= 0 && !$a['alerted_2']) { $alerts[] = ['msg'=>"Cita AHORA: ".$a['patient_name'], 'type'=>'danger']; $conn->query("UPDATE appointments SET alerted_2=1 WHERE id=".$a['id']); }
            }
            foreach ($data['rentals'] as &$r) {
                $diffDays = (strtotime($r['return_date']) - strtotime($now)) / 86400;
                if ($diffDays <= 1 && $diffDays > 0 && !$r['notified']) { $alerts[] = ['msg'=>"Devolver mañana: ".$r['product_name'], 'type'=>'info']; $conn->query("UPDATE rentals SET notified=1 WHERE id=".$r['id']); }
            }

            jsonOut(['status'=>'success', 'data'=>$data, 'alerts'=>$alerts]);
            break;

        // --- ADMIN ACTIONS ---
        case 'delete_user': if($currentUser['role']!=='dev' jsonOut(['status'=>'error']); $id = $input['id']; if($id == $currentUser['id']) jsonOut(['status'=>'error']); $conn->query("DELETE FROM users WHERE id=$id"); jsonOut(['status'=>'success']); break;
        case 'toggle_user': if($currentUser['role']!=='dev' jsonOut(['status'=>'error']); $id = $input['id']; $u = $conn->query("SELECT active FROM users WHERE id=$id")->fetch_assoc(); $ns = $u['active']==1?0:1; $conn->query("UPDATE users SET active=$ns WHERE id=$id"); jsonOut(['status'=>'success']); break;
        case 'renew_user': if($currentUser['role']!=='dev'  jsonOut(['status'=>'error']); $id = $input['id']; $date = $conn->real_escape_string($input['date']); $conn->query("UPDATE users SET expiry='$date' WHERE id=$id"); jsonOut(['status'=>'success']); break;
        case 'update_user': if($currentUser['role']!=='dev' jsonOut(['status'=>'error']); $id = $input['id']; $u = $conn->real_escape_string($input['username']); $p = $conn->real_escape_string($input['password']); $sql = "UPDATE users SET username='$u'"; if($p) $sql .= ", password='$p'"; $sql .= " WHERE id=$id"; $conn->query($sql); jsonOut(['status'=>'success']); break;
        case 'create_user': if($currentUser['role']!=='dev' jsonOut(['status'=>'error']); $u = $conn->real_escape_string($input['username']); $p = $conn->real_escape_string($input['password']); $r = $conn->real_escape_string($input['role']); $d = $conn->real_escape_string($input['expiry']); $conn->query("INSERT INTO users (username, password, role, expiry) VALUES ('$u', '$p', '$r', '$d')"); jsonOut(['status'=>'success']); break;
        
        // --- INVENTORY & SALES ---
        case 'update_product': 
            if($currentUser['role']!=='dev' && $currentUser['role']!=='admin') jsonOut(['status'=>'error']);
            $id = $input['id'];
            $n = $conn->real_escape_string($input['name']);
            $c = $conn->real_escape_string($input['cat']);
            $pr = $conn->real_escape_string($input['price']);
            $s = $conn->real_escape_string($input['stock']);
            $sql = "UPDATE products SET name='$n', category='$c', price=$pr, stock=$s WHERE id=$id";
            $conn->query($sql);
            jsonOut(['status'=>'success']);
            break;

        case 'save_patient': $n = $conn->real_escape_string($input['name']); $a = $conn->real_escape_string($input['age']); $ph = $conn->real_escape_string($input['phone']); $d = $conn->real_escape_string($input['desc']); $conn->query("INSERT INTO patients (name, age, phone, description) VALUES ('$n', '$a', '$ph', '$d')"); jsonOut(['status'=>'success']); break;
        case 'save_product': $n = $conn->real_escape_string($input['name']); $c = $conn->real_escape_string($input['cat']); $pr = $conn->real_escape_string($input['price']); $s = $conn->real_escape_string($input['stock']); $conn->query("INSERT INTO products (name, category, price, stock) VALUES ('$n', '$c', $pr, $s)"); jsonOut(['status'=>'success']); break;
        case 'save_appointment': $dt = $conn->real_escape_string($input['date']); $check = $conn->query("SELECT COUNT(*) as c FROM appointments WHERE HOUR(appointment_datetime)=HOUR('$dt') AND status='pending'")->fetch_assoc(); if($check['c'] >= 4) jsonOut(['status'=>'error', 'msg'=>'Máximo 4 citas/hora']); $pn = $conn->real_escape_string($input['name']); $ph = $conn->real_escape_string($input['phone']); $conn->query("INSERT INTO appointments (patient_name, phone, appointment_datetime) VALUES ('$pn', '$ph', '$dt')"); jsonOut(['status'=>'success']); break;
        case 'process_sale': $client = $conn->real_escape_string($input['client']); $total = $input['total']; $recv = $input['received']; $change = $input['change']; $details = $conn->real_escape_string(json_encode($input['items'])); $conn->query("INSERT INTO sales (client_name, total, method, received, change_amount, details) VALUES ('$client', $total, 'Efectivo', $recv, $change, '$details')"); foreach($input['items'] as $item) { $pid = $item['id']; $qty = $item['qty']; $conn->query("UPDATE products SET stock = stock - $qty WHERE id=$pid"); } jsonOut(['status'=>'success']); break;
        case 'create_rental': $pid = $input['prodId']; $pname = $conn->real_escape_string($input['prodName']); $pat = $conn->real_escape_string($input['patName']); $days = $input['days']; $end = date('Y-m-d', strtotime(date('Y-m-d'). " +$days days")); $start = date('Y-m-d H:i:s'); $conn->query("INSERT INTO rentals (product_id, product_name, patient_name, start_date, return_date) VALUES ($pid, '$pname', '$pat', '$start', '$end')"); $conn->query("UPDATE products SET stock = stock - 1 WHERE id=$pid"); jsonOut(['status'=>'success']); break;
        case 'return_rental': $id = $input['id']; $r = $conn->query("SELECT product_id FROM rentals WHERE id=$id")->fetch_assoc(); $conn->query("UPDATE rentals SET status='returned' WHERE id=$id"); $conn->query("UPDATE products SET stock = stock + 1 WHERE id=".$r['product_id']); jsonOut(['status'=>'success']); break;
        case 'renew_rental': $id = $input['id']; $days = $input['days']; $r = $conn->query("SELECT return_date FROM rentals WHERE id=$id")->fetch_assoc(); $nd = date('Y-m-d', strtotime($r['return_date'] . " +$days days")); $conn->query("UPDATE rentals SET return_date='$nd', notified=0 WHERE id=$id"); jsonOut(['status'=>'success']); break;
        case 'clean_history': $oneWeek = date('Y-m-d H:i:s', strtotime('-1 week')); $conn->query("DELETE FROM sales WHERE sale_date < '$oneWeek'"); jsonOut(['status'=>'success']); break;
        case 'save_config': $n = $conn->real_escape_string($input['name']); $conn->query("UPDATE settings SET clinic_name='$n' WHERE id=1"); jsonOut(['status'=>'success']); break;
        case 'attend_appointment': $id = $input['id']; $conn->query("UPDATE appointments SET status='attended' WHERE id=$id"); jsonOut(['status'=>'success']); break;

        default: jsonOut(['status'=>'error', 'msg'=>'Acción desconocida']);
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>OrtoSystem v10</title>
    <style>
        /* --- DISEÑO --- */
        :root {
            --primary: #0f172a; --accent: #2563eb; --bg-app: #f8fafc; --bg-card: #ffffff;
            --text-main: #1e293b; --text-light: #64748b; --success: #10b981; --danger: #ef4444; --warning: #f59e0b;
            --radius: 12px; --shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
        }
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Inter', sans-serif; -webkit-tap-highlight-color: transparent; }
        body { background-color: var(--bg-app); color: var(--text-main); height: 100vh; overflow: hidden; display: flex; flex-direction: column; }
        
        .icon { width: 20px; height: 20px; fill: currentColor; vertical-align: middle; }
        .btn { padding: 10px 20px; border-radius: var(--radius); border: none; font-weight: 600; cursor: pointer; transition: 0.2s; display: inline-flex; align-items: center; gap: 8px; font-size: 0.9rem; }
        .btn-primary { background: var(--accent); color: white; }
        .btn-danger { background: var(--danger); color: white; }
        .btn-outline { background: transparent; border: 1px solid #cbd5e1; color: var(--text-main); }
        .btn-sm { padding: 6px 12px; font-size: 0.8rem; }
        .card { background: var(--bg-card); border-radius: var(--radius); box-shadow: var(--shadow); padding: 20px; margin-bottom: 20px; border: 1px solid #e2e8f0; }
        input, select, textarea { width: 100%; padding: 10px; border: 1px solid #cbd5e1; border-radius: var(--radius); font-size: 0.95rem; margin-bottom: 10px; background: white; }
        input:focus, select:focus { outline: 2px solid var(--accent); border-color: transparent; }

        /* LAYOUT */
        #app-container { display: none; height: 100%; flex-direction: row; }
        aside { width: 260px; background: white; border-right: 1px solid #e2e8f0; display: flex; flex-direction: column; z-index: 50; }
        .brand { padding: 24px; font-size: 1.25rem; font-weight: 800; color: var(--primary); border-bottom: 1px solid #f1f5f9; display: flex; align-items: center; gap: 10px; }
        nav { padding: 20px 10px; flex: 1; overflow-y: auto; }
        .nav-item { display: flex; align-items: center; gap: 12px; padding: 12px 16px; border-radius: var(--radius); color: var(--text-light); cursor: pointer; margin-bottom: 4px; font-weight: 500; transition: 0.2s; }
        .nav-item:hover { background: #f8fafc; color: var(--text-main); }
        .nav-item.active { background: #eff6ff; color: var(--accent); font-weight: 600; }
        
        main { flex: 1; overflow-y: auto; padding: 30px; background: var(--bg-app); position: relative; }
        header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; }
        .user-pill { display: flex; align-items: center; gap: 10px; background: white; padding: 6px 12px; border-radius: 99px; border: 1px solid #e2e8f0; }
        .avatar { width: 32px; height: 32px; border-radius: 50%; background: #e2e8f0; object-fit: cover; }

        /* COMPONENTS */
        .tabs { display: flex; gap: 10px; margin-bottom: 20px; border-bottom: 2px solid #f1f5f9; padding-bottom: 10px; overflow-x: auto; }
        .tab-btn { padding: 10px 20px; border-radius: var(--radius); border: none; background: transparent; cursor: pointer; font-weight: 600; color: var(--text-light); border-bottom: 2px solid transparent; margin-bottom: -2px; transition: 0.2s; }
        .tab-btn.active { color: var(--accent); border-bottom-color: var(--accent); }
        .tab-content { display: none; }
        .tab-content.active { display: block; animation: fadeIn 0.3s ease; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(5px); } to { opacity: 1; transform: translateY(0); } }

        .table-wrap { background: white; border-radius: var(--radius); border: 1px solid #e2e8f0; overflow: hidden; overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; min-width: 600px; }
        th { background: #f8fafc; padding: 12px 16px; text-align: left; font-weight: 600; font-size: 0.85rem; color: var(--text-light); }
        td { padding: 12px 16px; border-bottom: 1px solid #f1f5f9; font-size: 0.9rem; white-space: nowrap; }
        
        .grid-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .stat-card { padding: 20px; background: white; border-radius: var(--radius); border: 1px solid #e2e8f0; text-align: center; }
        .stat-val { font-size: 2rem; font-weight: 800; color: var(--primary); }

        /* INVENTARIO */
        .product-list { display: flex; flex-direction: column; gap: 10px; max-height: 400px; overflow-y: auto; }
        .product-item { display: flex; justify-content: space-between; align-items: center; padding: 15px; background: white; border: 1px solid #e2e8f0; border-radius: 8px; cursor: pointer; transition: 0.2s; }
        .product-item:hover { border-color: var(--accent); background: #eff6ff; }
        .product-item:active { transform: scale(0.98); }
        .prod-info { display: flex; flex-direction: column; }
        .prod-name { font-weight: 600; }
        .prod-price { color: var(--text-light); font-size: 0.9rem; }

        /* TOASTS */
        #toast-container { position: fixed; top: 20px; right: 20px; z-index: 1000; display: flex; flex-direction: column; gap: 10px; pointer-events: none; }
        .toast { background: white; padding: 16px 20px; border-radius: var(--radius); box-shadow: 0 10px 15px -3px rgba(0,0,0,0.1); display: flex; align-items: center; gap: 12px; min-width: 300px; border-left: 4px solid var(--accent); animation: slideInRight 0.3s; pointer-events: auto; }
        .toast.success { border-left-color: var(--success); }
        .toast.error { border-left-color: var(--danger); }
        @keyframes slideInRight { from { transform: translateX(100%); opacity: 0; } to { transform: translateX(0); opacity: 1; } }

        /* MODAL */
        .modal { position: fixed; inset: 0; background: rgba(0,0,0,0.4); backdrop-filter: blur(4px); z-index: 2000; display: none; align-items: center; justify-content: center; }
        .modal-box { background: white; width: 95%; max-width: 500px; padding: 24px; border-radius: 16px; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.1); animation: zoomIn 0.2s; max-height: 90vh; overflow-y: auto; }
        @keyframes zoomIn { from { transform: scale(0.95); opacity: 0; } to { transform: scale(1); opacity: 1; } }

        /* MOBILE */
        .mobile-nav { display: none; position: fixed; bottom: 0; left: 0; width: 100%; background: white; border-top: 1px solid #e2e8f0; padding: 10px 0; z-index: 100; justify-content: space-around; }
        .mob-btn { display: flex; flex-direction: column; align-items: center; gap: 4px; font-size: 0.7rem; color: var(--text-light); text-decoration: none; flex: 1; }
        .mob-btn.active { color: var(--accent); font-weight: 600; }
        
        @media (max-width: 768px) {
            aside { display: none; }
            main { padding: 20px; padding-bottom: 80px; }
            .mobile-nav { display: flex; }
            .header-tools { width: 100%; justify-content: space-between; }
        }
    </style>
</head>
<body>

<!-- LOGIN -->
<div id="login-screen" style="position:fixed; inset:0; background: linear-gradient(135deg, var(--primary) 0%, #334155 100%); display:flex; align-items:center; justify-content:center; z-index:3000;">
    <div class="card" style="width:90%; max-width:400px; text-align:center; padding:40px;">
        <div style="font-size:3rem; margin-bottom:10px;">🦴</div>
        <h2 style="margin-bottom:5px; color:var(--primary);">OrtoSystem</h2>
        <p style="color:var(--text-light); margin-bottom:30px;">Centro Ortopedico Yumbo</p>
        
        <form id="login-form">
            <input type="text" id="l-user" name="user" placeholder="Usuario" autocomplete="username">
            <input type="password" id="l-pass" name="pass" placeholder="Contraseña" autocomplete="current-password">
            <div style="display:flex; align-items:center; justify-content:start; gap:8px; margin-bottom:20px;">
                <input type="checkbox" id="l-remember" name="remember" style="width:auto; margin:0;"> 
                <label for="l-remember" style="font-size:0.9rem; color:var(--text-light);">Recordar usuario</label>
            </div>
            <button type="submit" class="btn btn-primary" style="width:100%; justify-content:center;">Entrar</button>
        </form>
    </div>
</div>

<!-- APP -->
<div id="app-container">
    <!-- Sidebar -->
    <aside>
        <div class="brand">
            <svg class="icon" viewBox="0 0 24 24"><path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-7 3c1.93 0 3.5 1.57 3.5 3.5S13.93 13 12 13s-3.5-1.57-3.5-3.5S10.07 6 12 6zm7 13H5v-.23c0-.62.28-1.2.76-1.58C7.47 15.82 9.64 15 12 15s4.53.82 6.24 2.19c.48.38.76.97.76 1.58V19z"/></svg>
            <span id="brand-name">Ortopedia</span>
        </div>
        <nav id="main-nav"></nav>
        <div style="padding:20px; border-top: 1px solid #f1f5f9;">
            <button class="btn btn-danger" style="width:100%" onclick="app.logout()">Salir</button>
        </div>
    </aside>

    <!-- Main Content -->
    <main>
        <header>
            <h1 id="page-heading" style="font-size:1.8rem; font-weight:700; color:var(--primary);">Panel</h1>
            <div class="header-tools" style="display:flex; gap:15px; align-items:center;">
                <div style="position:relative; cursor:pointer;" onclick="app.showAlerts()">
                    <svg class="icon" viewBox="0 0 24 24"><path d="M12 22c1.1 0 2-.9 2-2h-4c0 1.1.9 2 2 2zm6-6v-5c0-3.07-1.63-5.64-4.5-6.32V4c0-.83-.67-1.5-1.5-1.5s-1.5.67-1.5 1.5v.68C7.64 5.36 6 7.92 6 11v5l-2 2v1h16v-1l-2-2z"/></svg>
                    <div id="notif-badge" style="position:absolute; top:-5px; right:-5px; background:var(--danger); color:white; font-size:10px; padding:2px 6px; border-radius:10px; display:none;">0</div>
                </div>
                <div class="user-pill">
                    <img src="" id="u-avatar" class="avatar">
                    <span id="u-name" style="font-weight:600;">User</span>
                </div>
            </div>
        </header>

        <div id="content-area"></div>
    </main>

    <!-- Mobile Nav -->
    <nav class="mobile-nav" id="mob-nav"></nav>
</div>

<!-- TOAST CONTAINER -->
<div id="toast-container"></div>

<!-- MODAL -->
<div id="modal-container" class="modal">
    <div class="modal-box">
        <div style="display:flex; justify-content:space-between; margin-bottom:20px;">
            <h3 id="modal-title">Título</h3>
            <span onclick="app.closeModal()" style="cursor:pointer; font-size:1.5rem;">&times;</span>
        </div>
        <div id="modal-body"></div>
    </div>
</div>

<script>
const api = (action, data = {}) => fetch('?api=' + action, {
    method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(data)
}).then(r => r.json());

const $ = (s) => document.querySelector(s);

const Utils = {
    money: (a) => '$' + parseFloat(a).toFixed(2).replace(/\d(?=(\d{3})+\.)/g, '$&,'),
    date: (d) => new Date(d).toLocaleDateString(),
    fullDate: (d) => new Date(d).toLocaleString([], {weekday:'short', day:'numeric', month:'short', hour:'2-digit', minute:'2-digit'})
};

const app = {
    data: null,
    user: null,
    cart: [],
    audioCtx: null,

    toast: (msg, type = 'success') => {
        const c = $('#toast-container');
        const el = document.createElement('div');
        el.className = `toast ${type}`;
        let icon = type === 'success' ? '✔' : type === 'error' ? '✖' : 'ℹ';
        el.innerHTML = `<span style="font-size:1.2rem; font-weight:bold;">${icon}</span> <span>${msg}</span>`;
        c.appendChild(el);
        setTimeout(() => { el.style.opacity='0'; setTimeout(()=>el.remove(), 300); }, 3000);
        
        // Sound
        if(!app.audioCtx) app.audioCtx = new (window.AudioContext || window.webkitAudioContext)();
        const osc = app.audioCtx.createOscillator();
        const gain = app.audioCtx.createGain();
        osc.connect(gain); gain.connect(app.audioCtx.destination);
        osc.frequency.value = 600; gain.gain.value = 0.05;
        osc.start(); setTimeout(() => osc.stop(), 150);
    },

    // --- VIEWS ---
    views: {
        dashboard: { title: 'Panel', icon: '<path d="M3 13h8V3H3v10zm0 8h8v-6H3v6zm10 0h8V11h-8v10zm0-18v6h8V3h-8z"/>', role: 'all', render: () => app.renderDashboard() },
        patients: { title: 'Pacientes', icon: '<path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/>', role: 'all', render: () => app.renderPatients() },
        inventory: { title: 'Inventario', icon: '<path d="M20 13H4c-.55 0-1 .45-1 1v6c0 .55.45 1 1 1h16c.55 0 1-.45 1-1v-6c0-.55-.45-1-1-1z"/>', role: 'all', render: () => app.renderInventory() },
        appointments: { title: 'Agenda', icon: '<path d="M19 3h-1V1h-2v2H8V1H6v2H5c-1.11 0-1.99.9-1.99 2L3 19c0 1.1.89 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm0 16H5V8h14v11z"/>', role: 'all', render: () => app.renderAppointments() },
        rentals: { title: 'Alquileres', icon: '<path d="M20.57 14.86L22 13.43 20.57 12 17 15.57 8.43 7 12 3.43 10.57 2 9.14 3.43 7.71 2 5.57 4.14 4.14 2.71 2.71 4.14l1.43 1.43L2 7.71l1.43 1.43L2 10.57 3.43 12 7 8.43 15.57 17 12 20.57 13.43 22 14.86 20.57 16.29 22 18.43 19.86 19.86 21.29 21.29 19.86 19.86 18.43 22 16.29 19.86z"/>', role: 'all', render: () => app.renderRentals() },
        admin: { title: 'Admin', icon: '<path d="M19.43 12.98c.04-.32.07-.64.07-.98s-.03-.66-.07-.98l2.11-1.65c.19-.15.24-.42.12-.64l-2-3.46c-.12-.22-.39-.3-.61-.22l-2.49 1c-.52-.4-1.08-.73-1.69-.98l-.38-2.65C14.46 2.18 14.25 2 14 2h-4c-.25 0-.46.18-.49.42l-.38 2.65c-.61.25-1.17.59-1.69.98l-2.49-1c-.23-.09-.49 0-.61.22l-2 3.46c-.13.22-.07.49.12.64l2.11 1.65c-.04.32-.07.65-.07.98s.03.66.07.98l-2.11 1.65c-.19.15-.24.42-.12.64l2 3.46c.12.22.39.3.61.22l2.49-1c.52.4 1.08.73 1.69.98l.38 2.65c.03.24.24.42.49.42h4c.25 0 .46-.18.49-.42l.38-2.65c.61-.25 1.17-.59 1.69-.98l2.49 1c.23.09.49 0 .61-.22l2-3.46c.12-.22.07-.49.12-.64l-2.11-1.65z"/>', role: 'admin', render: () => app.renderAdmin() },
        developer: { title: 'Developer', icon: '<path d="M20 4h-3.17L15 2H9L7.17 4H4c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2z"/>', role: 'dev', render: () => app.renderDeveloper() }
    },

    init: async () => {
        // Construct Nav
        const nav = $('#main-nav'); const mob = $('#mob-nav');
        let navHtml = ''; let mobHtml = '';
        
        for(const [key, v] of Object.entries(app.views)) {
            navHtml += `<div class="nav-item" id="nav-${key}" onclick="app.nav('${key}')"><svg class="icon" viewBox="0 0 24 24">${v.icon}</svg> ${v.title}</div>`;
            mobHtml += `<a class="mob-btn" id="mob-${key}" onclick="app.nav('${key}')"><svg class="icon" viewBox="0 0 24 24">${v.icon}</svg>${v.title}</a>`;
        }
        nav.innerHTML = navHtml; mob.innerHTML = mobHtml;

        // Check Session
        const res = await api('get_data');
        if(res.status === 'success') {
            app.data = res.data;
            app.user = app.data.users.find(u => u.id === $_SESSION['user']['id']) || $_SESSION['user'];
            $('#login-screen').style.display = 'none';
            $('#app-container').style.display = 'flex';
            app.setupUser();
        } else {
            // Si falla get_data (por ejemplo sesión corrupta), asegurarnos de que login sea visible
            $('#login-screen').style.display = 'flex';
            $('#app-container').style.display = 'none';
        }
    },

    login: async (e) => {
        e.preventDefault(); // Evitar recarga de página
        const u = $('#l-user').value.trim(); 
        const p = $('#l-pass').value.trim();
        
        if(!u || !p) return app.toast('Llena todos los campos', 'warning');

        const res = await api('login', {user: u, pass: p});
        if(res.status === 'success') {
            app.user = res.user;
            $('#login-screen').style.display = 'none';
            $('#app-container').style.display = 'flex';
            if($('#l-remember').checked) localStorage.setItem('ortho_user', u);
            app.loadData(); // Cargar datos
            app.setupUser();
        } else {
            app.toast(res.msg, 'error');
        }
    },

    logout: async () => { if(confirm('Cerrar sesión?')) { await api('logout'); location.reload(); } },

    setupUser: () => {
        $('#u-name').innerText = app.user.username;
        $('#u-avatar').src = app.user.photo || `https://encrypted-tbn0.gstatic.com/images?q=tbn:ANd9GcSE6uXVnzuh_bn45jbmKPiUBRXWtZ6wYVwtwA&s`;
        $('#brand-name').innerText = app.data.settings.clinic_name;

        // Roles Logic
        document.querySelectorAll('.nav-item, .mob-btn').forEach(el => el.style.display = 'none');
        
        for(const [key, v] of Object.entries(app.views)) {
            if(v.role === 'dev' && app.user.role !== 'dev') continue;
            if(v.role === 'admin' && app.user.role !== 'admin' && app.user.role !== 'dev') continue;
            
            $(`#nav-${key}`).style.display = 'flex';
            $(`#mob-${key}`).style.display = 'flex';
        }

        app.loadData();
        app.nav('dashboard');
    },

    nav: (key) => {
        document.querySelectorAll('.nav-item, .mob-btn').forEach(el => el.classList.remove('active'));
        $(`#nav-${key}`).classList.add('active');
        $(`#mob-${key}`).classList.add('active');
        $('#page-heading').innerText = app.views[key].title;
        $('#content-area').innerHTML = '';
        app.views[key].render();
        
        if(key === 'appointments') app.startClocks();
        else app.stopClocks();
    },

    loadData: async () => {
        const res = await api('get_data');
        if(res.status === 'success') {
            app.data = res.data;
            const count = res.alerts.length;
            if(count > 0 && document.hidden === false) {
                res.alerts.forEach(a => app.toast(a.msg, a.type === 'danger' ? 'error' : (a.type === 'warning' ? 'warning' : 'info')));
            }
        }
        const activeView = $('.nav-item.active');
        if(activeView) app.views[activeView.id.replace('nav-','')].render();
    },

    // --- RENDERERS ---
    renderDashboard: () => {
        const totalSales = app.data.sales.reduce((a,b)=>a+parseFloat(b.total),0);
        $('#content-area').innerHTML = `
            <div class="grid-stats">
                <div class="stat-card"><div class="stat-val">${app.data.patients.length}</div><div>Pacientes</div></div>
                <div class="stat-card"><div class="stat-val">${app.data.appointments.length}</div><div>Citas</div></div>
                <div class="stat-card"><div class="stat-val">${app.data.rentals.length}</div><div>Alquileres</div></div>
                <div class="stat-card"><div class="stat-val">${Utils.money(totalSales)}</div><div>Ventas</div></div>
            </div>
            <div class="card"><h3>Bienvenido, ${app.user.username}</h3></div>
        `;
    },

    renderPatients: () => {
        $('#content-area').innerHTML = `
            <div style="text-align:right; margin-bottom:15px;"><button class="btn btn-primary" onclick="app.modal('patient')">+ Paciente</button></div>
            <div class="table-wrap">
                <table><thead><tr><th>Nombre</th><th>Edad</th><th>Teléfono</th></tr></thead>
                <tbody>${app.data.patients.map(p => `<tr><td>${p.name}</td><td>${p.age}</td><td>${p.phone}</td></tr>`).join('')}</tbody></table>
            </div>
        `;
    },

    renderInventory: () => {
        app.cart = [];
        $('#content-area').innerHTML = `
            <div style="text-align:right; margin-bottom:15px;"><button class="btn btn-primary" onclick="app.modal('product')">+ Producto</button></div>
            <div class="tabs">
                <button class="tab-btn active" onclick="app.switchTab('inv', 'venta')">Punto de Venta</button>
                <button class="tab-btn" onclick="app.switchTab('inv', 'alquiler')">Alquiler</button>
            </div>
            
            <div id="tab-inv-venta" class="tab-content active">
                <div style="display:grid; grid-template-columns: 2fr 1fr; gap:20px;">
                    <div>
                        <h4>Productos (Click para agregar)</h4>
                        <div class="product-list">
                            ${app.data.products.filter(p=>p.category==='venta').map(p => `
                                <div class="product-item" onclick="app.addToCart('${p.id}')">
                                    <div class="prod-info"><div class="prod-name">${p.name}</div><div class="prod-price">${Utils.money(p.price)}</div></div>
                                    <div style="font-size:0.8rem; color:#64748b;">Stock: ${p.stock}</div>
                                </div>
                            `).join('')}
                        </div>
                    </div>
                    <div class="card" style="margin:0; padding:15px;">
                        <h4>Carrito</h4>
                        <div id="cart-list" style="min-height:50px;">Vacío</div>
                        <div style="border-top:1px solid #eee; padding-top:15px; text-align:right; font-weight:bold; font-size:1.2rem;">Total: <span id="cart-total">$0.00</span></div>
                        <input type="text" id="pos-client" placeholder="Cliente">
                        <label>Recibido</label><input type="number" id="pos-received" placeholder="0.00" oninput="app.calcChange()">
                        <div style="margin-bottom:15px;">Cambio: <span id="pos-change">$0.00</span></div>
                        <button class="btn btn-primary" style="width:100%; justify-content:center;" onclick="app.processSale()">COBRAR</button>
                    </div>
                </div>
            </div>

            <div id="tab-inv-alquiler" class="tab-content">
                <div class="table-wrap">
                    <table><thead><tr><th>Producto</th><th>Stock</th><th>Acción</th></tr></thead>
                    <tbody>${app.data.products.filter(p=>p.category==='alquiler').map(p => `<tr><td>${p.name}</td><td>${p.stock}</td><td><button class="btn btn-primary btn-sm" onclick="app.modal('rent', '${p.id}')">Alquilar</button></td></tr>`).join('')}</tbody></table>
                </div>
            </div>
        `;
        app.renderCart();
    },

    renderAppointments: () => {
        $('#content-area').innerHTML = `
            <div style="text-align:right; margin-bottom:15px;"><button class="btn btn-primary" onclick="app.modal('apt')">+ Cita</button></div>
            <div class="agenda-filters" style="display:flex; gap:8px; margin-bottom:15px; flex-wrap:wrap;">
                <button class="filter-btn active" onclick="app.filterAgenda('all', this)">Todas</button>
                <button class="filter-btn" onclick="app.filterAgenda('morning', this)">Mañana</button>
                <button class="filter-btn" onclick="app.filterAgenda('afternoon', this)">Tarde</button>
                <button class="filter-btn" onclick="app.filterAgenda('evening', this)">Noche</button>
            </div>
            <div class="table-wrap">
                <table><thead><tr><th>Fecha</th><th>Paciente</th><th>Tiempo</th><th>Acción</th></tr></thead>
                <tbody id="apt-list-body"></tbody>
            </table>
            <div id="apt-empty-msg" class="card" style="text-align:center; display:none;">No hay citas pendientes en este horario.</div>
        `;
        app.renderAgendaList('all');
        setTimeout(app.startClocks, 100);
    },

    renderRentals: () => {
        $('#content-area').innerHTML = `
            <div class="table-wrap">
                <table><thead><tr><th>Producto</th><th>Paciente</th><th>Devolución</th><th>Acción</th></tr></thead>
                <tbody>${app.data.rentals.map(r => `<tr><td>${r.product_name}</td><td>${r.patient_name}</td><td>${Utils.date(r.return_date)}</td><td><button class="btn btn-danger btn-sm" onclick="app.returnR(${r.id})">Devolver</button> <button class="btn btn-outline btn-sm" onclick="app.renewR(${r.id})">Renovar</button></td></tr>`).join('')}</tbody></table>
            </div>
        `;
    },

    renderAdmin: () => {
        $('#content-area').innerHTML = `
            <div class="card">
                <h3>Usuarios</h3>
                <button class="btn btn-sm" style="margin-bottom:10px;" onclick="app.modal('user')">+ Crear Usuario</button>
                <div class="table-wrap">
                    <table><thead><tr><th>User</th><th>Rol</th><th>Estado</th><th>Acción</th></tr></thead>
                    <tbody>${app.data.users.map(u => `<tr><td>${u.username}</td><td>${u.role}</td><td>${u.active?'Activo':'Bloqueado'}</td><td>${u.role !== 'dev' ? `<button class="btn btn-outline btn-sm" onclick="app.modal('editUser', '${u.id}')">Editar</button> <button class="btn btn-outline btn-sm" onclick="app.modal('renewUser', '${u.id}')">Renovar</button> <button class="btn btn-outline btn-sm" onclick="app.toggleUser('${u.id}')">${u.active?'Bloquear':'Activar'}</button> <button class="btn btn-danger btn-sm" onclick="app.deleteUser('${u.id}')">Eliminar</button>` : ''}</td></tr>`).join('')}</tbody></table>
                </div>
            </div>
        `;
    },

    renderDeveloper: () => { $('#content-area').innerHTML = `<div class="card" style="border:2px solid red;"><h3>Developer Mode</h3><p>Acceso Total.</p></div>`; },

    // --- ACTIONS ---
    switchTab: (g, n) => {
        document.querySelectorAll(`.tab-btn`).forEach(b => b.classList.remove('active'));
        event.target.classList.add('active');
        document.querySelectorAll(`[id^="tab-${g}-"]`).forEach(d => d.classList.remove('active'));
        $(`#tab-${g}-${n}`).classList.add('active');
    },

    addToCart: (id) => {
        const p = app.data.products.find(x=>x.id==id);
        if(p.stock <= 0) return app.toast('Sin stock', 'warning');
        const ex = app.cart.find(c => c.id === id);
        if(ex) { if(ex.qty < p.stock) ex.qty++; }
        else { app.cart.push({...p, qty:1}); }
        app.toast(`Agregado: ${p.name}`);
        app.renderCart();
    },
    renderCart: () => {
        const display = $('#cart-list');
        if(app.cart.length === 0) display.innerHTML = '<p style="color:#94a3b8; text-align:center;">Vacío</p>';
        else display.innerHTML = app.cart.map((c,i) => `<div style="display:flex; justify-content:space-between; margin-bottom:8px; background:#f9f9f9; padding:8px; border-radius:4px;"><span>${c.name} x${c.qty}</span><span onclick="app.delCart(${i})" style="color:red; cursor:pointer;">&times;</span></div>`).join('');
        const t = app.cart.reduce((a,b)=>a+(b.price*b.qty),0);
        $('#cart-total').innerText = Utils.money(t);
        app.calcChange();
    },
    delCart: (i) => { app.cart.splice(i,1); app.renderCart(); },
    calcChange: () => { const total = app.cart.reduce((a,b)=>a+(b.price*b.qty),0); const recv = parseFloat($('#pos-received').value)||0; $('#pos-change').innerText = Utils.money(recv-total); },
    processSale: async () => {
        if(app.cart.length === 0) return app.toast('Carrito vacío', 'error');
        const client = $('#pos-client').value; if(!client) return app.toast('Falta nombre cliente', 'warning');
        const total = app.cart.reduce((a,b)=>a+(b.price*b.qty),0);
        const recv = parseFloat($('#pos-received').value) || 0;
        if(recv < total) return app.toast('Falta dinero', 'error');
        await api('process_sale', {client, total, received: recv, change: recv-total, items: app.cart});
        app.toast('Venta Exitosa', 'success');
        app.cart = []; app.renderCart(); app.loadData();
    },

    attend: async (id) => { await api('attend_appointment', {id}); app.toast('Atendida', 'success'); app.loadData(); },
    returnR: async (id) => { if(!confirm('Devolver?')) return; await api('return_rental', {id}); app.toast('Devuelto', 'success'); app.loadData(); },
    renewR: async (id) => { const d = prompt('Días:', '7'); if(d) { await api('renew_rental', {id, days:d}); app.toast('Renovado', 'success'); app.loadData(); } },
    toggleUser: async (id) => { await api('toggle_user', {id}); app.toast('Actualizado', 'success'); app.loadData(); },
    deleteUser: async (id) => { if(!confirm('Borrar?')) return; const res = await api('delete_user', {id}); if(res.status === 'success') { app.toast('Borrado', 'success'); app.loadData(); } },

    // --- AGENDA ---
    filterAgenda: (period, btn) => {
        document.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        app.renderAgendaList(period);
    },
    renderAgendaList: (period) => {
        let list = app.data.appointments;
        if (period !== 'all') {
            const now = new Date();
            list = list.filter(a => {
                const d = new Date(a.appointment_datetime);
                if (d.toDateString() !== now.toDateString()) return false;
                const hour = d.getHours();
                if (period === 'morning' && hour < 12) return true;
                if (period === 'afternoon' && hour >= 12 && hour < 18) return true;
                if (period === 'evening' && hour >= 18) return true;
                return false;
            });
        }
        
        const tbody = $('#apt-list-body');
        const emptyMsg = $('#apt-empty-msg');
        if(list.length === 0) {
            tbody.innerHTML = ''; 
            emptyMsg.style.display = 'block';
        } else {
            emptyMsg.style.display = 'none';
            tbody.innerHTML = list.map(a => `
                <tr>
                    <td>${new Date(a.appointment_datetime).toLocaleString([], {month:'short', day:'numeric', hour:'2-digit', minute:'2-digit'})}</td>
                    <td>${a.patient_name}</td>
                    <td class="timer-live" data-time="${a.appointment_datetime}" style="font-family:monospace; font-weight:bold;">...</td>
                    <td><button class="btn btn-success btn-sm" onclick="app.attend(${a.id})">✔</button></td>
                </tr>
            `).join('');
            // Restart clocks logic just in case
            app.stopClocks();
            setTimeout(app.startClocks, 100);
        }
    },
    clocksInterval: null,
    startClocks: () => {
        app.stopClocks();
        app.clocksInterval = setInterval(() => {
            document.querySelectorAll('.timer-live').forEach(el => {
                const target = new Date(el.dataset.time);
                const now = new Date();
                const diff = target - now;
                if (diff < 0) { el.innerText = "AHORA"; el.style.color = "red"; }
                else {
                    const mins = Math.floor(diff / 60000);
                    const secs = Math.floor((diff % 60000) / 1000);
                    el.innerText = `${mins}m ${secs}s`;
                    el.style.color = mins < 10 ? "red" : "black";
                }
            });
        }, 1000);
    },
    stopClocks: () => { if(app.clocksInterval) clearInterval(app.clocksInterval); },

    // --- MODALS ---
    modal: (type, id = null) => {
        let html = '';
        if(type === 'patient') html = `<form onsubmit="app.save(event, 'patient')"><input name="name" placeholder="Nombre" required><input name="age" placeholder="Edad"><input name="phone" placeholder="Teléfono"><textarea name="desc" placeholder="Descripción"></textarea><button class="btn btn-primary">Guardar</button></form>`;
        else if(type === 'apt') html = `<form onsubmit="app.save(event, 'apt')"><input name="name" placeholder="Paciente" required><input name="phone" placeholder="Teléfono"><input name="date" type="datetime-local" required><button class="btn btn-primary">Agendar</button></form>`;
        else if(type === 'user') html = `<form onsubmit="app.save(event, 'user')"><input name="username" placeholder="Usuario"><input name="password" placeholder="Contraseña"><select name="role"><option value="staff">Staff</option><option value="admin">Admin</option></select><input name="expiry" type="date" required><button class="btn btn-primary">Crear</button></form>`;
        else if(type === 'product') html = `<form onsubmit="app.save(event, 'product')"><input name="name" placeholder="Nombre" required><select name="cat"><option value="venta">Venta</option><option value="alquiler">Alquiler</option></select><input name="price" type="number" step="0.01" placeholder="Precio" required><input name="stock" type="number" placeholder="Stock" required><button class="btn btn-primary">Guardar</button></form>`;
        else if(type === 'rent') {
            const p = app.data.products.find(x=>x.id===id);
            html = `<div style="text-align:center;"><h4>Alquilar: ${p.name}</h4><input id="rent-pat" placeholder="Paciente"><input id="rent-days" type="number" value="7"><button class="btn btn-primary" onclick="app.doRent('${p.id}', '${p.name}')" style="width:100%; justify-content:center; margin-top:10px;">Confirmar</button></div>`;
        }
        else if(type === 'editUser') {
            const u = app.data.users.find(x=>x.id===id);
            html = `<input type="hidden" id="edit-u-id" value="${u.id}"><input id="edit-u-name" value="${u.username}" placeholder="Usuario"><input id="edit-u-pass" placeholder="Nueva Contraseña (vacio = no cambiar)"><button class="btn btn-primary" onclick="app.doUpdateUser()">Actualizar</button>`;
        }
        else if(type === 'renewUser') {
            const u = app.data.users.find(x=>x.id===id);
            html = `<input type="hidden" id="renew-u-id" value="${u.id}"><h4>Renovar: ${u.username}</h4><input id="renew-u-date" type="date"><button class="btn btn-primary" onclick="app.doRenewUser()">Guardar</button>`;
        }
        else if(type === 'editProduct') {
            const p = app.data.products.find(x=>x.id===id);
            html = `
                <h4 style="margin-bottom:10px;">Editar Producto</h4>
                <input type="hidden" id="edit-prod-id" value="${p.id}">
                <input id="edit-prod-name" value="${p.name}" placeholder="Nombre">
                <select id="edit-prod-cat">
                    <option value="venta" ${p.category=='venta'?'selected':''}>Venta</option>
                    <option value="alquiler" ${p.category=='alquiler'?'selected':''}>Alquiler</option>
                </select>
                <input id="edit-prod-price" type="number" step="0.01" value="${p.price}" placeholder="Precio">
                <input id="edit-prod-stock" type="number" value="${p.stock}" placeholder="Stock">
                <button class="btn btn-primary" style="width:100%; margin-top:10px;" onclick="app.doUpdateProduct()">Actualizar</button>
            `;
        }
        
        $('#modal-title').innerText = type.charAt(0).toUpperCase() + type.slice(1);
        $('#modal-body').innerHTML = html;
        $('#modal-container').style.display = 'flex';
    },

    closeModal: () => $('#modal-container').style.display = 'none',
    save: async (e, type) => {
        e.preventDefault();
        const fd = new FormData(e.target); const data = Object.fromEntries(fd.entries());
        if(type === 'patient') await api('save_patient', data);
        if(type === 'apt') await api('save_appointment', {...data, date: data.date});
        if(type === 'user') await api('create_user', {...data, expiry: data.expiry});
        if(type === 'product') await api('save_product', data);
        app.closeModal(); app.toast('Guardado'); app.loadData();
    },

    doRent: async (id, name) => {
        const pat = $('#rent-pat').value; const days = $('#rent-days').value;
        if(!pat) return app.toast('Falta nombre', 'warning');
        await api('create_rental', {prodId: id, prodName: name, patName: pat, days});
        app.closeModal(); app.toast('Alquilado'); app.loadData();
    },

    // --- NUEVOS: EDITAR PRODUCTO ---
    doUpdateProduct: async () => {
        const id = $('#edit-prod-id').value;
        const n = $('#edit-prod-name').value;
        const c = $('#edit-prod-cat').value;
        const pr = $('#edit-prod-price').value;
        const s = $('#edit-prod-stock').value;
        if(!n) return app.toast('Falta nombre', 'warning');
        await api('update_product', {id, name:n, cat:c, price:pr, stock:s});
        app.closeModal(); app.toast('Producto Actualizado', 'success'); app.loadData();
    },

    doUpdateUser: async () => { const id = $('#edit-u-id').value; const u = $('#edit-u-name').value; const p = $('#edit-u-pass').value; await api('update_user', {id, username:u, password:p}); app.closeModal(); app.toast('Actualizado', 'success'); app.loadData(); },
    doRenewUser: async () => { const id = $('#renew-u-id').value; const d = $('#renew-u-date').value; await api('renew_user', {id, date:d}); app.closeModal(); app.toast('Renovado', 'success'); app.loadData(); },

    showAlerts: () => app.toast('Revisa tus notificaciones', 'info')
};

// --- EVENT LISTENERS ---
 $('#login-form').addEventListener('submit', app.login);
// Init
app.init();
</script>
</body>
</html>