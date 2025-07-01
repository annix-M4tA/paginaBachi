<?php
function getEvents() {
    global $conexion; // Use the global connection variable from conexion.php
    
    $events = [];
    $query = "SELECT * FROM eventos ORDER BY fecha_creacion DESC";
    
    // Prepare and execute the query
    $result = $conexion->query($query);
    
    if ($result && $result->num_rows > 0) {
        $events = $result->fetch_all(MYSQLI_ASSOC);
        $result->free(); // Free the result set
    }
    
    return $events;
}
?>