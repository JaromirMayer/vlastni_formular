<?php
/**
 * Plugin Name: vlastni_formular
 * Plugin URI: https://github.com/JaromirMayer/vlastni_formular
 * Description: Formulář (jméno, příjmení, email, zpráva, příloha) s DB, admin rozhraním, filtrováním, exportem, hromadným mazáním, validací a ochranou proti spamu bez reCAPTCHA.
 * Version: 1.3
 * Author: Jaromir Mayer
 * Author URI: https://github.com/JaromirMayer
 * Text Domain: vlastni_formular
 * Domain Path: /languages
 */

// Zajištění automatických aktualizací přes GitHub
if (!class_exists('Puc_v4_Factory')) {
    require_once plugin_dir_path(__FILE__) . 'plugin-update-checker/plugin-update-checker.php';
}
$updateChecker = Puc_v4_Factory::buildUpdateChecker(
    'https://api.github.com/repos/JaromirMayer/vlastni_formular', // URL repozitáře
    __FILE__, // hlavní plugin soubor
    'vlastni_formular' // slug pluginu
);
$updateChecker->setBranch('main');

// 1) Aktivace: vytvoření DB tabulky
register_activation_hook(__FILE__, function() {
    global $wpdb;
    $table = $wpdb->prefix . 'vlastni_formular';
    $charset = $wpdb->get_charset_collate();
    $sql = "CREATE TABLE $table (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        jmeno tinytext NOT NULL,
        prijmeni tinytext NOT NULL,
        email text NOT NULL,
        zprava text NOT NULL,
        attachment_url text,
        created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
        PRIMARY KEY  (id)
    ) $charset;";
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
});

// 2) Shortcode pro formulář
add_shortcode('vlastni_formular', function() {
    ob_start(); ?>
    <style>
      .formular-box { background-color: #cce4f7; padding:20px; border-radius:8px; max-width:500px; }
      .formular-box label { display:block; margin-bottom:8px; color:white; }
      .formular-box input, .formular-box textarea { width:100%; padding:8px; margin-top:4px; border:none; border-radius:4px; }
      .formular-box input[type="submit"] { background:#888; color:white; padding:10px 15px; border:none; border-radius:4px; cursor:pointer; }
      .formular-box .hp { display:none; }
    </style>
    <form method="post" enctype="multipart/form-data" class="formular-box">
      <?php wp_nonce_field('formular_nonce_action','formular_nonce'); ?>
      <label>Jméno: <input type="text" name="jmeno" required></label>
      <label>Příjmení: <input type="text" name="prijmeni" required></label>
      <label>Email: <input type="email" name="email" required></label>
      <label>Zpráva: <textarea name="zprava" required></textarea></label>
      <label>Soubor: <input type="file" name="soubor"></label>
      <div class="hp"><label>Nevyplňujte toto pole: <input type="text" name="hp"></label></div>
      <input type="submit" name="formular_submit" value="Odeslat">
    </form>
    <?php
    if(isset($_POST['formular_submit'])) formular_handle_submission();
    return ob_get_clean();
});

// 3) Zpracování odeslání
function formular_handle_submission() {
    if(!isset($_POST['formular_nonce']) || !wp_verify_nonce($_POST['formular_nonce'],'formular_nonce_action')) {
      echo '<p>Ověření selhalo.</p>'; return;
    }
    if(!empty($_POST['hp'])) {
      echo '<p>Spam detekován.</p>'; return;
    }

    global $wpdb;
    $table = $wpdb->prefix.'vlastni_formular';

    $jmeno    = sanitize_text_field($_POST['jmeno']);
    $prijmeni = sanitize_text_field($_POST['prijmeni']);
    $email    = sanitize_email($_POST['email']);
    $zprava   = sanitize_textarea_field($_POST['zprava']);
    $attachment_url = '';
    $attachment_file = '';

    if(!is_email($email)) {
      echo '<p>Neplatný email.</p>'; return;
    }

    // Nahrání souboru
    if (!empty($_FILES['soubor']['name'])) {
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        $uploaded = wp_handle_upload($_FILES['soubor'], ['test_form' => false]);
        if (!isset($uploaded['error'])) {
            $attachment_url = esc_url_raw($uploaded['url']);
            $attachment_file = $uploaded['file'];
        }
    }

    $wpdb->insert($table, compact('jmeno','prijmeni','email','zprava','attachment_url'));

    // Příprava e-mailu
    $admin_email = get_option('admin_email');
    $subject = 'Nový záznam z formuláře';
    $body = "Jméno: $jmeno\nPříjmení: $prijmeni\nEmail: $email\n\nZpráva:\n$zprava";
    $attachments = $attachment_file ? [$attachment_file] : [];

    // Odeslání e-mailu s přílohou
    wp_mail($admin_email, $subject, $body, [], $attachments);
    wp_mail($email, 'Kopie vašeho odeslaného formuláře', $body, [], $attachments);

    echo '<p>Děkujeme, váš formulář byl odeslán.</p>';
}

// 4) Admin rozhraní
add_action('admin_menu', function(){
    add_menu_page('Formulář Data','Formulář','manage_options','formular-admin','formular_admin_page','dashicons-feedback',25);
});

function formular_admin_page() {
    global $wpdb;
    $table = $wpdb->prefix.'vlastni_formular';

    // Hromadné mazání
    if(!empty($_POST['delete_ids']) && is_array($_POST['delete_ids'])) {
      foreach($_POST['delete_ids'] as $id) {
        $wpdb->delete($table, ['id'=>intval($id)]);
      }
      echo '<div class="updated"><p>Záznamy smazány.</p></div>';
    }

    // Filtrování
    $where = "1=1";
    foreach(['jmeno','prijmeni','email'] as $f) {
      if(!empty($_GET["filter_$f"])) {
        $val = sanitize_text_field($_GET["filter_$f"]);
        $where .= $wpdb->prepare(" AND $f LIKE %s", "%{$wpdb->esc_like($val)}%");
      }
    }

    // Export CSV
    if(isset($_POST['export_csv'])) {
      $rows = $wpdb->get_results("SELECT * FROM $table WHERE $where ORDER BY created_at DESC", ARRAY_A);
      header('Content-Type:text/csv; charset=utf-8');
      header('Content-Disposition: attachment; filename="export.csv"');
      $out = fopen('php://output','w');
      fputcsv($out,['ID','Jméno','Příjmení','Email','Zpráva','Příloha','Datum']);
      foreach($rows as $row) fputcsv($out,[$row['id'],$row['jmeno'],$row['prijmeni'],$row['email'],$row['zprava'],$row['attachment_url'],$row['created_at']]);
      fclose($out);
      exit;
    }

    $results = $wpdb->get_results("SELECT * FROM $table WHERE $where ORDER BY created_at DESC");

    echo '<div class="wrap"><h1>Správa formulářů</h1>';
    echo '<form method="get"><input type="hidden" name="page" value="formular-admin">';
    foreach(['jmeno'=>'Jméno','prijmeni'=>'Příjmení','email'=>'Email'] as $f=>$label) {
      echo "$label: <input type=\"text\" name=\"filter_$f\" value=\"".esc_attr($_GET["filter_$f"]??'')."\"> ";
    }
    echo '<input type="submit" class="button button-primary" value="Filtrovat"> ';
    echo '<a href="'.admin_url('admin.php?page=formular-admin').'" class="button">Zrušit filtr</a></form><br>';

    echo '<form method="post">';
    echo '<input type="submit" name="export_csv" class="button button-secondary" value="Export CSV"> ';
    echo '<input type="submit" class="button button-danger" value="Smazat vybrané" onclick="return confirm(\'Smazat vybrané?\')">';
    echo '<table class="widefat striped"><thead><tr>
      <th></th><th>ID</th><th>Jméno</th><th>Příjmení</th><th>Email</th><th>Zpráva</th><th>Příloha</th><th>Datum</th>
    </tr></thead><tbody>';
    foreach($results as $r){
      echo '<tr>
        <td><input type="checkbox" name="delete_ids[]" value="'.$r->id.'"></td>
        <td>'.$r->id.'</td>
        <td>'.esc_html($r->jmeno).'</td>
        <td>'.esc_html($r->prijmeni).'</td>
        <td>'.esc_html($r->email).'</td>
        <td>'.esc_html($r->zprava).'</td>
        <td>'.($r->attachment_url ? '<a href="'.esc_url($r->attachment_url).'" target="_blank">Zobrazit</a>' : '').'</td>
        <td>'.esc_html($r->created_at).'</td>
      </tr>';
    }
    echo '</tbody></table></form></div>';
}
