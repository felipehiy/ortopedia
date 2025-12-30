<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

// CREDENCIALES DE BASE DE DATOS (Tu info real)
 $host = 'xxxxxxxxxxxxx';
 $user = 'xxxxxxxxxxxxx';
 $pass = 'xxxxxxxxxxxxx';
 $db   = 'xxxxxxxxxxxxx';

 $conn = new mysqli($host, $user, $pass, $db);
if ($conn->connect_error) die(json_encode(['success' => false, 'message' => 'Error de conexión DB']));

 $action = $_GET['action'] ?? '';
 $input = json_decode(file_get_contents('php://input'), true);

// FUNCIÓN RESPUESTA
function response($data) { echo json_encode($data); exit; }

switch($action) {
    // --- LOGIN ---
    case 'login':
        $u = $input['user'];
        $p = $input['pass'];
        $stmt = $conn->prepare("SELECT * FROM users WHERE username = ? AND password = ?");
        $stmt->bind_param("ss", $u, $p);
        $stmt->execute();
        $res = $stmt->get_result()->fetch_assoc();
        
        if($res) {
            if($res['active'] == 0) response(['success'=>false, 'message'=>'Usuario Bloqueado']);
            if($res['role'] != 'dev' && $res['expiry'] < date('Y-m-d')) response(['success'=>false, 'message'=>'Membresía Vencida']);
            response(['success'=>true, 'user'=>$res]);
        } else {
            response(['success'=>false, 'message'=>'Credenciales Incorrectas']);
        }
        break;

    // --- LEER DATOS ---
    case 'get_data':
        $data = [];
        $data['patients'] = $conn->query("SELECT * FROM patients")->fetch_all(MYSQLI_ASSOC);
        $data['products'] = $conn->query("SELECT * FROM products")->fetch_all(MYSQLI_ASSOC);
        $data['appointments'] = $conn->query("SELECT * FROM appointments ORDER BY appointment_datetime ASC")->fetch_all(MYSQLI_ASSOC);
        $data['rentals'] = $conn->query("SELECT * FROM rentals WHERE status = 'active'")->fetch_all(MYSQLI_ASSOC);
        $data['sales'] = $conn->query("SELECT * FROM sales ORDER BY sale_date DESC")->fetch_all(MYSQLI_ASSOC);
        $data['settings'] = $conn->query("SELECT * FROM settings")->fetch_assoc();
        response(['success'=>true, 'data'=>$data]);
        break;

    // --- ADMIN: BLOQUEAR/DESBLOQUEAR USUARIO ---
    case 'toggle_user':
        if(!isset($input['id'])) response(['success'=>false]);
        $id = $input['id'];
        // Primero obtenemos el estado actual
        $curr = $conn->query("SELECT active FROM users WHERE id=$id")->fetch_assoc();
        $newStatus = $curr['active'] == 1 ? 0 : 1;
        $conn->query("UPDATE users SET active=$newStatus WHERE id=$id");
        response(['success'=>true, 'new_status'=>$newStatus]);
        break;

    // --- ADMIN: RENOVAR MEMBRESÍA ---
    case 'renew_user':
        $id = $input['id']; $date = $input['date'];
        $conn->query("UPDATE users SET expiry='$date' WHERE id=$id");
        response(['success'=>true]);
        break;

    // --- ADMIN: CREAR USUARIO ---
    case 'create_user':
        $u = $input['username']; $p = $input['password']; $r = $input['role']; $d = $input['expiry'];
        $conn->query("INSERT INTO users (username, password, role, expiry) VALUES ('$u', '$p', '$r', '$d')");
        response(['success'=>true]);
        break;

    // --- CREAR PACIENTE ---
    case 'save_patient':
        $n = $input['name']; $a = $input['age']; $p = $input['phone']; $d = $input['desc'];
        $conn->query("INSERT INTO patients (name, age, phone, description) VALUES ('$n', '$a', '$p', '$d')");
        response(['success'=>true]);
        break;

    // --- CREAR CITA ---
    case 'save_appointment':
        // Validar max 4 citas a la misma hora
        $dt = $input['date'];
        $check = $conn->query("SELECT COUNT(*) as c FROM appointments WHERE HOUR(appointment_datetime) = HOUR('$dt') AND status='pending'")->fetch_assoc();
        if($check['c'] >= 4) response(['success'=>false, 'message'=>'Máximo 4 citas por hora']);

        $pn = $input['name']; $ph = $input['phone'];
        $conn->query("INSERT INTO appointments (patient_name, phone, appointment_datetime) VALUES ('$pn', '$ph', '$dt')");
        response(['success'=>true]);
        break;

    // --- ATENDER CITA ---
    case 'attend_appointment':
        $id = $input['id'];
        $conn->query("UPDATE appointments SET status='attended' WHERE id=$id");
        response(['success'=>true]);
        break;

    // --- CREAR PRODUCTO ---
    case 'save_product':
        $n = $input['name']; $c = $input['cat']; $pr = $input['price']; $s = $input['stock'];
        $conn->query("INSERT INTO products (name, category, price, stock) VALUES ('$n', '$c', $pr, $s)");
        response(['success'=>true]);
        break;

    // --- PROCESAR VENTA (POS) ---
    case 'process_sale':
        $client = $conn->real_escape_string($input['client']);
        $total = $input['total'];
        $method = $input['method'];
        $recv = $input['received'];
        $change = $input['change'];
        $details = json_encode($input['items']); // Guardar carrito en JSON
        
        $conn->query("INSERT INTO sales (client_name, total, method, received, change_amount, details) VALUES ('$client', $total, '$method', $recv, $change, '$details')");
        
        // Descontar Stock
        foreach($input['items'] as $item) {
            $pid = $item['id']; $qty = $item['qty'];
            $conn->query("UPDATE products SET stock = stock - $qty WHERE id=$pid");
        }
        response(['success'=>true]);
        break;

    // --- ALQUILER ---
    case 'create_rental':
        $pid = $input['prodId']; $pname = $conn->real_escape_string($input['prodName']);
        $pat = $conn->real_escape_string($input['patName']);
        $start = date('Y-m-d H:i:s');
        $end = $input['endDate'];
        
        $conn->query("INSERT INTO rentals (product_id, product_name, patient_name, start_date, return_date) VALUES ($pid, '$pname', '$pat', '$start', '$end')");
        $conn->query("UPDATE products SET stock = stock - 1 WHERE id=$pid");
        response(['success'=>true]);
        break;

    case 'return_rental':
        $id = $input['id'];
        $r = $conn->query("SELECT * FROM rentals WHERE id=$id")->fetch_assoc();
        $conn->query("UPDATE rentals SET status='returned' WHERE id=$id");
        $conn->query("UPDATE products SET stock = stock + 1 WHERE id=".$r['product_id']);
        response(['success'=>true]);
        break;

    case 'renew_rental':
        $id = $input['id']; $days = $input['days'];
        $r = $conn->query("SELECT return_date FROM rentals WHERE id=$id")->fetch_assoc();
        $newDate = date('Y-m-d', strtotime($r['return_date'] . " +$days days"));
        $conn->query("UPDATE rentals SET return_date='$newDate', notified=0 WHERE id=$id");
        response(['success'=>true]);
        break;

    // --- ALERTAS ---
    case 'check_alerts':
        $alerts = [];
        
        // Citas
        $now = date('Y-m-d H:i:s');
        $apts = $conn->query("SELECT * FROM appointments WHERE status='pending' AND appointment_datetime > '$now'")->fetch_all(MYSQLI_ASSOC);
        
        foreach($apts as $a) {
            $diff = (strtotime($a['appointment_datetime']) - strtotime($now)) / 60;
            if($diff <= 10 && $diff > 5 && !$a['alerted_10']) {
                $alerts[] = ['msg' => "Cita en 10 min: " . $a['patient_name'], 'type'=>'apt'];
                $conn->query("UPDATE appointments SET alerted_10=1 WHERE id=".$a['id']);
            }
            if($diff <= 5 && $diff > 2 && !$a['alerted_5']) {
                $alerts[] = ['msg' => "Cita en 5 min: " . $a['patient_name'], 'type'=>'apt'];
                $conn->query("UPDATE appointments SET alerted_5=1 WHERE id=".$a['id']);
            }
            if($diff <= 2 && $diff >= 0 && !$a['alerted_2']) {
                $alerts[] = ['msg' => "Cita AHORA: " . $a['patient_name'], 'type'=>'apt'];
                $conn->query("UPDATE appointments SET alerted_2=1 WHERE id=".$a['id']);
            }
        }

        // Alquileres (1 dia antes)
        $rents = $conn->query("SELECT * FROM rentals WHERE status='active'")->fetch_all(MYSQLI_ASSOC);
        foreach($rents as $r) {
            $diffDays = (strtotime($r['return_date']) - strtotime($now)) / 86400;
            if($diffDays <= 1 && $diffDays > 0 && !$r['notified']) {
                $alerts[] = ['msg' => "Devolver mañana: " . $r['product_name'], 'type'=>'rent'];
                $conn->query("UPDATE rentals SET notified=1 WHERE id=".$r['id']);
            }
        }
        response(['success'=>true, 'alerts'=>$alerts]);
        break;

    // Limpiar Historial Viejo (> 1 semana)
    case 'clean_history':
        $oneWeek = date('Y-m-d H:i:s', strtotime('-1 week'));
        $conn->query("DELETE FROM sales WHERE sale_date < '$oneWeek'");
        response(['success'=>true]);
        break;

    // Guardar Config
    case 'save_config':
        $name = $conn->real_escape_string($input['name']);
        $conn->query("UPDATE settings SET clinic_name='$name' WHERE id=1");
        response(['success'=>true]);
        break;
        
    default: response(['success'=>false, 'message'=>'Acción desconocida']);
}
?>