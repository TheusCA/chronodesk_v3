<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../init.php';

verificar_login();

global $gerenciador;
$metricas = $gerenciador->obter_metricas();
json_response($metricas);

