/**
 * Helper centralizado para llamadas a la API con JWT.
 *
 * Agrega automáticamente el token Bearer a cada petición
 * y redirige al login si el token expiró (HTTP 401).
 *
 * USO:
 * const result = await api('../index.php?action=user');
 * const result = await api('../index.php?action=user', {
 *     method: 'POST',
 *     body: JSON.stringify({ ... })
 * });
 */
async function api(url, options = {}) {
    // Obtener token guardado en el login
    const token = sessionStorage.getItem('jwt_token');
    
    // Preparar headers: siempre JSON
    options.headers = {
        'Content-Type': 'application/json',
        ...options.headers,
    };
    
    // Agregar token si existe
    if (token) {
        options.headers['Authorization'] = `Bearer ${token}`;
    }
    
    // Hacer la petición
    const response = await fetch(url, options);
    const result = await response.json();
    
    // Si el token expiró (401), redirigir al login
    if (response.status === 401 && !url.includes('action=login')) {
        sessionStorage.clear();
        window.location.href = 'login.html';
        return null;
    }
    return result;
}
