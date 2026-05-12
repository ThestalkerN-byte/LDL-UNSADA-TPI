<?php
function hashPassword($password) {
    return password_hash($password, PASSWORD_BCRYPT);
}

function validarEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL);
}
?>