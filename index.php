<?php
require 'vendor/autoload.php';

class ScrapingGoogleMaps {
    private $api_key;
    private $provincias;
    private $localidades;
    private $terminos_busqueda;
    private $rate_limit = 0; // Tracks API calls for rate limiting
    private $max_requests_per_day = 100000; // Adjust based on your API quota
    private $datos_consolidados = []; // Store all data for possible consolidated reporting

    public function __construct($api_key, $provincias, $localidades, $terminos_busqueda) {
        $this->api_key = $api_key;
        $this->provincias = $provincias;
        $this->localidades = $localidades;
        $this->terminos_busqueda = $terminos_busqueda;
    }

    private function hacerPeticion($url) {
        // Implement rate limiting
        if ($this->rate_limit >= $this->max_requests_per_day) {
            throw new Exception("Límite diario de API alcanzado. Intente mañana.");
        }
        $this->rate_limit++;
        
        // Add error handling for API requests
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10); 
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        
        $respuesta = curl_exec($ch);
        
        if (curl_errno($ch)) {
            $error_msg = curl_error($ch);
            curl_close($ch);
            throw new Exception("Error en la petición cURL: $error_msg");
        }
        
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($http_code != 200) {
            throw new Exception("Error en la respuesta HTTP: $http_code. Respuesta: $respuesta");
        }
        
        $json_result = json_decode($respuesta, true);
        
        if (isset($json_result['status']) && $json_result['status'] !== 'OK' && $json_result['status'] !== 'ZERO_RESULTS') {
            throw new Exception("Error en la API de Google: {$json_result['status']}. " . 
                                (isset($json_result['error_message']) ? $json_result['error_message'] : ''));
        }
        
        return $json_result;
    }

    private function obtenerLugares($ubicacion, $termino, $token_pagina = null) {
        $url_base = "https://maps.googleapis.com/maps/api/place/textsearch/json";
        $parametros = [
            'query' => "$termino en $ubicacion", // "en" might be more natural in Spanish than "cerca de"
            'language' => 'es', // Set language to Spanish
            'key' => $this->api_key
        ];
        
        if ($token_pagina) {
            $parametros['pagetoken'] = $token_pagina;
        }

        $url = $url_base . '?' . http_build_query($parametros);
        
        try {
            return $this->hacerPeticion($url);
        } catch (Exception $e) {
            echo "Error al obtener lugares para '$termino' en '$ubicacion': " . $e->getMessage() . "\n";
            return ['results' => []]; // Return empty results to continue execution
        }
    }

    private function obtenerDetallesLugar($id_lugar) {
        $url_base = "https://maps.googleapis.com/maps/api/place/details/json";
        $parametros = [
            'place_id' => $id_lugar,
            'fields' => 'name,formatted_address,formatted_phone_number,website,rating,url,international_phone_number,place_id,types',
            'language' => 'es', // Set language to Spanish
            'key' => $this->api_key
        ];

        $url = $url_base . '?' . http_build_query($parametros);
        
        try {
            return $this->hacerPeticion($url);
        } catch (Exception $e) {
            echo "Error al obtener detalles para el lugar ID '$id_lugar': " . $e->getMessage() . "\n";
            return ['result' => []]; // Return empty result to continue execution
        }
    }

    private function formatearDatos($lugar, $provincia, $ubicacion, $termino) {
        // Check if result is empty
        if (empty($lugar)) {
            return null;
        }
        
        $maps_url = isset($lugar['url']) ? $lugar['url'] : 
                   (isset($lugar['place_id']) ? "https://maps.google.com/?cid=" . $lugar['place_id'] : '');
                   
        $tipos = isset($lugar['types']) ? implode(', ', $lugar['types']) : '';
        
        return [
            'Nombre' => isset($lugar['name']) ? $lugar['name'] : '',
            'Dirección' => isset($lugar['formatted_address']) ? $lugar['formatted_address'] : '',
            'Provincia' => $provincia,
            'Localidad' => $ubicacion,
            'Teléfono' => isset($lugar['formatted_phone_number']) ? $lugar['formatted_phone_number'] : '',
            'Teléfono Internacional' => isset($lugar['international_phone_number']) ? $lugar['international_phone_number'] : '',
            'WhatsApp' => '', // Google API doesn't provide WhatsApp info directly
            'Correo electrónico' => '', // Google API doesn't provide email directly
            'Sitio web' => isset($lugar['website']) ? $lugar['website'] : '',
            'Calificación' => isset($lugar['rating']) ? $lugar['rating'] : '',
            'Tipos de negocio' => $tipos,
            'Servicios ofrecidos' => $termino,
            'Término de búsqueda' => $termino,
            'Fuente' => 'Google Maps API',
            'URL' => $maps_url
        ];
    }
    
    // Método modificado para escanear una localidad específica
    public function escanearLocalidad($provincia, $localidad) {
        echo "Escaneando localidad: $localidad, $provincia...\n";
        
        $objPHPExcel = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $objPHPExcel->getActiveSheet()->setTitle(substr($localidad, 0, 31)); // Excel sheet name limit is 31 chars
        
        // Aplicar estilo a encabezados
        $objPHPExcel->getActiveSheet()->getStyle('A1:P1')->getFont()->setBold(true);
        $objPHPExcel->getActiveSheet()->getStyle('A1:P1')->getFill()
            ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
            ->getStartColor()->setRGB('DDDDDD');

        // Agregar encabezados
        $encabezados = [
            'Nombre', 'Dirección', 'Provincia', 'Localidad', 'Teléfono', 'Teléfono Internacional',
            'WhatsApp', 'Correo electrónico', 'Sitio web', 'Calificación', 'Tipos de negocio',
            'Servicios ofrecidos', 'Término de búsqueda', 'Fuente', 'URL', 'Fecha de extracción'
        ];
        $objPHPExcel->getActiveSheet()->fromArray([$encabezados], null, 'A1');
        
        // Auto-ajustar anchos de columna
        foreach(range('A','P') as $columnID) {
            $objPHPExcel->getActiveSheet()->getColumnDimension($columnID)->setAutoSize(true);
        }

        $fila = 2;
        $duplicates_check = []; // To avoid duplicate entries
        $fecha_extraccion = date('Y-m-d H:i:s');
        
        foreach ($this->terminos_busqueda as $termino) {
            echo "  Buscando '$termino' en $localidad...\n";
            $token_pagina = null;
            $page_count = 0;
            $max_pages = 10; // Aumentado para obtener más resultados
            
            do {
                try {
                    // Ahora buscamos específicamente en la localidad
                    $resultados = $this->obtenerLugares("$localidad, $provincia", $termino, $token_pagina);
                    
                    if (isset($resultados['results']) && count($resultados['results']) > 0) {
                        echo "    Encontrados " . count($resultados['results']) . " resultados (página " . ($page_count + 1) . ")\n";
                        
                        foreach ($resultados['results'] as $lugar) {
                            // Skip if we've already processed this place_id
                            $place_id = $lugar['place_id'];
                            if (isset($duplicates_check[$place_id])) {
                                continue;
                            }
                            $duplicates_check[$place_id] = true;
                            
                            $detalles = $this->obtenerDetallesLugar($place_id);
                            
                            if (isset($detalles['result']) && !empty($detalles['result'])) {
                                $datos = $this->formatearDatos($detalles['result'], $provincia, $localidad, $termino);
                                
                                if ($datos) {
                                    // Add extraction date
                                    $datos['Fecha de extracción'] = $fecha_extraccion;
                                    
                                    // Add to spreadsheet
                                    $objPHPExcel->getActiveSheet()->fromArray([$datos], null, 'A' . $fila);
                                    $fila++;
                                    
                                    // Store for possible consolidated report
                                    $this->datos_consolidados[] = $datos;
                                }
                            }
                            
                            // Pause briefly between detail requests to avoid rate limits
                            usleep(500000); // 0.5 seconds
                        }
                    } else {
                        echo "    No se encontraron resultados para '$termino' en $localidad\n";
                    }
                    
                    $token_pagina = isset($resultados['next_page_token']) ? $resultados['next_page_token'] : null;
                    
                    if ($token_pagina) {
                        $page_count++;
                        if ($page_count >= $max_pages) {
                            echo "    Límite de páginas alcanzado ($max_pages)\n";
                            $token_pagina = null;
                        } else {
                            echo "    Esperando para la siguiente página...\n";
                            sleep(2); // Wait for next_page_token to become valid
                        }
                    }
                } catch (Exception $e) {
                    echo "ERROR: " . $e->getMessage() . "\n";
                    $token_pagina = null; // Stop pagination on error
                }
            } while ($token_pagina);
            
            // Guardar después de cada término de búsqueda para evitar pérdida de datos
            $this->guardarExcelLocalidad($objPHPExcel, $provincia, $localidad);
            
            // Sleep between search terms
            sleep(2);
        }

        return $this->guardarExcelLocalidad($objPHPExcel, $provincia, $localidad);
    }
    
    private function guardarExcelLocalidad($objPHPExcel, $provincia, $localidad) {
        // Create directory structure if it doesn't exist
        $directorio = 'resultados/' . $this->sanitizarNombreArchivo($provincia);
        if (!file_exists($directorio)) {
            mkdir($directorio, 0777, true);
        }
        
        // Save Excel file
        $objWriter = \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($objPHPExcel, 'Xlsx');
        $nombre_archivo = $directorio . "/empresas_" . $this->sanitizarNombreArchivo($localidad) . ".xlsx";
        
        // Try to save with retries
        $max_retries = 3;
        $retry_count = 0;
        $saved = false;
        
        while (!$saved && $retry_count < $max_retries) {
            try {
                $objWriter->save($nombre_archivo);
                $saved = true;
            } catch (Exception $e) {
                $retry_count++;
                echo "Error al guardar el archivo (intento $retry_count): " . $e->getMessage() . "\n";
                sleep(2);
            }
        }
        
        if (!$saved) {
            echo "ERROR: No se pudo guardar el archivo '$nombre_archivo' después de $max_retries intentos.\n";
            return null;
        }
        
        echo "  Archivo guardado: $nombre_archivo\n";
        return $nombre_archivo;
    }
    
    private function sanitizarNombreArchivo($nombre) {
        // Remove special characters and spaces
        $nombre = strtolower(trim($nombre));
        $nombre = str_replace(' ', '_', $nombre);
        $nombre = preg_replace('/[^a-z0-9_]/i', '', $nombre);
        return $nombre;
    }

    // Método para escanear todas las localidades de una provincia
    public function escanearLocalidadesProvincia($provincia) {
        $tiempo_inicio = microtime(true);
        $archivos_generados = [];
        
        if (!isset($this->localidades[$provincia])) {
            echo "ERROR: No se encontraron localidades para la provincia '$provincia'\n";
            return false;
        }
        
        echo "Escaneando todas las localidades de $provincia...\n";
        
        foreach ($this->localidades[$provincia] as $localidad) {
            $inicio_localidad = microtime(true);
            
            $archivo = $this->escanearLocalidad($provincia, $localidad);
            if ($archivo) {
                $archivos_generados[] = $archivo;
            }
            
            $tiempo_localidad = round((microtime(true) - $inicio_localidad) / 60, 2);
            echo "Completado $localidad en $tiempo_localidad minutos\n";
            
            // Sleep between localities to limit API usage
            echo "Esperando antes de la siguiente localidad...\n";
            sleep(5);
        }
        
        $tiempo_total = round((microtime(true) - $tiempo_inicio) / 60, 2);
        echo "Proceso completado para $provincia en $tiempo_total minutos. Archivos generados: " . count($archivos_generados) . "\n";
        
        return $archivos_generados;
    }

    // Método para escanear todas las provincias y sus localidades
    public function escanearTodasLasProvinciasYLocalidades() {
        $tiempo_inicio = microtime(true);
        $archivos_generados = [];
        
        // Create log file
        $log_file = 'resultados/log_' . date('Y-m-d_H-i-s') . '.txt';
        file_put_contents($log_file, "Inicio de escaneo completo: " . date('Y-m-d H:i:s') . "\n");
        
        try {
            foreach ($this->provincias as $provincia) {
                $inicio_provincia = microtime(true);
                echo "Escaneando provincia: $provincia...\n";
                
                $archivos = $this->escanearLocalidadesProvincia($provincia);
                if ($archivos) {
                    $archivos_generados = array_merge($archivos_generados, $archivos);
                }
                
                $tiempo_provincia = round((microtime(true) - $inicio_provincia) / 60, 2);
                echo "Completada provincia $provincia en $tiempo_provincia minutos\n";
                
                // Log progress
                file_put_contents($log_file, "Provincia $provincia completada en $tiempo_provincia minutos\n", FILE_APPEND);
                
                // Sleep between provinces to limit API usage
                echo "Esperando antes de la siguiente provincia...\n";
                sleep(10);
            }
            
            // Generate consolidated report
            $this->generarReporteConsolidado();
            
            $tiempo_total = round((microtime(true) - $tiempo_inicio) / 60, 2);
            echo "Proceso completo finalizado en $tiempo_total minutos. Archivos generados: " . count($archivos_generados) . "\n";
            
            // Log completion
            file_put_contents($log_file, "Proceso completo finalizado en $tiempo_total minutos. Archivos generados: " . count($archivos_generados) . "\n", FILE_APPEND);
        } catch (Exception $e) {
            echo "ERROR CRÍTICO: " . $e->getMessage() . "\n";
            file_put_contents($log_file, "ERROR CRÍTICO: " . $e->getMessage() . "\n", FILE_APPEND);
        }
    }

    // Método para escanear una única localidad
    public function escanearUnaLocalidad($provincia, $localidad) {
        if (!in_array($provincia, $this->provincias)) {
            echo "ERROR: La provincia '$provincia' no está en la lista de provincias configuradas.\n";
            return false;
        }
        
        if (!isset($this->localidades[$provincia]) || !in_array($localidad, $this->localidades[$provincia])) {
            echo "ERROR: La localidad '$localidad' no está en la lista de localidades para la provincia '$provincia'.\n";
            return false;
        }
        
        echo "Escaneando localidad: $localidad en $provincia\n";
        $archivo = $this->escanearLocalidad($provincia, $localidad);
        
        if ($archivo) {
            echo "Escaneo completado. Archivo generado: $archivo\n";
            return true;
        } else {
            echo "Error al escanear la localidad.\n";
            return false;
        }
    }

    private function generarReporteConsolidado() {
        if (empty($this->datos_consolidados)) {
            echo "No hay datos para generar un reporte consolidado.\n";
            return null;
        }
        
        echo "Generando reporte consolidado...\n";
        
        try {
            $objPHPExcel = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
            $objPHPExcel->getActiveSheet()->setTitle('Consolidado');
            
            // Aplicar estilo a encabezados
            $objPHPExcel->getActiveSheet()->getStyle('A1:P1')->getFont()->setBold(true);
            $objPHPExcel->getActiveSheet()->getStyle('A1:P1')->getFill()
                ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                ->getStartColor()->setRGB('DDDDDD');
            
            // Agregar encabezados
            $encabezados = [
                'Nombre', 'Dirección', 'Provincia', 'Localidad', 'Teléfono', 'Teléfono Internacional',
                'WhatsApp', 'Correo electrónico', 'Sitio web', 'Calificación', 'Tipos de negocio',
                'Servicios ofrecidos', 'Término de búsqueda', 'Fuente', 'URL', 'Fecha de extracción'
            ];
            $objPHPExcel->getActiveSheet()->fromArray([$encabezados], null, 'A1');
            
            // Auto-ajustar anchos de columna
            foreach(range('A','P') as $columnID) {
                $objPHPExcel->getActiveSheet()->getColumnDimension($columnID)->setAutoSize(true);
            }
            
            // Agregar todos los datos
            $objPHPExcel->getActiveSheet()->fromArray($this->datos_consolidados, null, 'A2');
            
            // Crear una segunda hoja con estadísticas
            $objPHPExcel->createSheet();
            $objPHPExcel->setActiveSheetIndex(1);
            $objPHPExcel->getActiveSheet()->setTitle('Estadísticas');
            
            // Estadísticas por provincia
            $objPHPExcel->getActiveSheet()->setCellValue('A1', 'Estadísticas por Provincia');
            $objPHPExcel->getActiveSheet()->getStyle('A1')->getFont()->setBold(true);
            $objPHPExcel->getActiveSheet()->setCellValue('A2', 'Provincia');
            $objPHPExcel->getActiveSheet()->setCellValue('B2', 'Cantidad de registros');
            
            $stats_provincia = [];
            foreach ($this->datos_consolidados as $datos) {
                if (!isset($stats_provincia[$datos['Provincia']])) {
                    $stats_provincia[$datos['Provincia']] = 0;
                }
                $stats_provincia[$datos['Provincia']]++;
            }
            
            $row = 3;
            foreach ($stats_provincia as $provincia => $cantidad) {
                $objPHPExcel->getActiveSheet()->setCellValue('A' . $row, $provincia);
                $objPHPExcel->getActiveSheet()->setCellValue('B' . $row, $cantidad);
                $row++;
            }
            
            // Estadísticas por localidad
            $row += 2;
            $objPHPExcel->getActiveSheet()->setCellValue('A' . $row, 'Estadísticas por Localidad');
            $objPHPExcel->getActiveSheet()->getStyle('A' . $row)->getFont()->setBold(true);
            $row++;
            $objPHPExcel->getActiveSheet()->setCellValue('A' . $row, 'Localidad');
            $objPHPExcel->getActiveSheet()->setCellValue('B' . $row, 'Provincia');
            $objPHPExcel->getActiveSheet()->setCellValue('C' . $row, 'Cantidad de registros');
            $row++;
            
            $stats_localidad = [];
            foreach ($this->datos_consolidados as $datos) {
                $key = $datos['Localidad'] . '|' . $datos['Provincia'];
                if (!isset($stats_localidad[$key])) {
                    $stats_localidad[$key] = [
                        'localidad' => $datos['Localidad'],
                        'provincia' => $datos['Provincia'],
                        'cantidad' => 0
                    ];
                }
                $stats_localidad[$key]['cantidad']++;
            }
            
            foreach ($stats_localidad as $info) {
                $objPHPExcel->getActiveSheet()->setCellValue('A' . $row, $info['localidad']);
                $objPHPExcel->getActiveSheet()->setCellValue('B' . $row, $info['provincia']);
                $objPHPExcel->getActiveSheet()->setCellValue('C' . $row, $info['cantidad']);
                $row++;
            }
            
            // Estadísticas por término de búsqueda
            $row += 2;
            $objPHPExcel->getActiveSheet()->setCellValue('A' . $row, 'Estadísticas por Término');
            $objPHPExcel->getActiveSheet()->getStyle('A' . $row)->getFont()->setBold(true);
            $row++;
            $objPHPExcel->getActiveSheet()->setCellValue('A' . $row, 'Término');
            $objPHPExcel->getActiveSheet()->setCellValue('B' . $row, 'Cantidad de registros');
            $row++;
            
            $stats_termino = [];
            foreach ($this->datos_consolidados as $datos) {
                if (!isset($stats_termino[$datos['Término de búsqueda']])) {
                    $stats_termino[$datos['Término de búsqueda']] = 0;
                }
                $stats_termino[$datos['Término de búsqueda']]++;
            }
            
            foreach ($stats_termino as $termino => $cantidad) {
                $objPHPExcel->getActiveSheet()->setCellValue('A' . $row, $termino);
                $objPHPExcel->getActiveSheet()->setCellValue('B' . $row, $cantidad);
                $row++;
            }
            
            // Resumen general
            $row += 2;
            $objPHPExcel->getActiveSheet()->setCellValue('A' . $row, 'Resumen General');
            $objPHPExcel->getActiveSheet()->getStyle('A' . $row)->getFont()->setBold(true);
            $row++;
            
            $objPHPExcel->getActiveSheet()->setCellValue('A' . $row, 'Registros totales:');
            $objPHPExcel->getActiveSheet()->setCellValue('B' . $row, count($this->datos_consolidados));
            $row++;
            
            $objPHPExcel->getActiveSheet()->setCellValue('A' . $row, 'Provincias escaneadas:');
            $objPHPExcel->getActiveSheet()->setCellValue('B' . $row, count($stats_provincia));
            $row++;
            
            $objPHPExcel->getActiveSheet()->setCellValue('A' . $row, 'Localidades escaneadas:');
            $objPHPExcel->getActiveSheet()->setCellValue('B' . $row, count($stats_localidad));
            $row++;
            
            $objPHPExcel->getActiveSheet()->setCellValue('A' . $row, 'Términos utilizados:');
            $objPHPExcel->getActiveSheet()->setCellValue('B' . $row, count($stats_termino));
            $row++;
            
            $objPHPExcel->getActiveSheet()->setCellValue('A' . $row, 'Fecha de generación:');
            $objPHPExcel->getActiveSheet()->setCellValue('B' . $row, date('Y-m-d H:i:s'));
            
            // Auto-ajustar anchos de columna
            foreach(range('A','C') as $columnID) {
                $objPHPExcel->getActiveSheet()->getColumnDimension($columnID)->setAutoSize(true);
            }
            
            // Volver a la primera hoja
            $objPHPExcel->setActiveSheetIndex(0);
            
            // Guardar archivo Excel
            $directorio = 'resultados';
            $nombre_archivo = $directorio . "/empresas_reporte_consolidado_" . date('Y-m-d') . ".xlsx";
            
            $objWriter = \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($objPHPExcel, 'Xlsx');
            $objWriter->save($nombre_archivo);
            
            echo "Reporte consolidado generado: $nombre_archivo\n";
            return $nombre_archivo;
        } catch (Exception $e) {
            echo "Error al generar reporte consolidado: " . $e->getMessage() . "\n";
            return null;
        }
    }
    
    // Método para establecer un límite máximo de API requests
    public function establecerLimiteRequests($limite) {
        if ($limite > 0) {
            $this->max_requests_per_day = $limite;
            echo "Límite de peticiones establecido a $limite\n";
            return true;
        } else {
            echo "ERROR: El límite debe ser un número positivo.\n";
            return false;
        }
    }
    
    // Verificar estado de conexión a la API
    public function verificarConexionAPI() {
        echo "Verificando conexión a la API de Google Maps...\n";
        
        try {
            // Hacer una petición simple para verificar que la API key funciona
            $url_base = "https://maps.googleapis.com/maps/api/place/details/json";
            $parametros = [
                'place_id' => 'ChIJN1t_tDeuEmsRUsoyG83frY4', // Sydney Opera House como ejemplo
                'fields' => 'name',
                'key' => $this->api_key
            ];
            
            $url = $url_base . '?' . http_build_query($parametros);
            $resultado = $this->hacerPeticion($url);
            
            if (isset($resultado['result']['name'])) {
                echo "Conexión exitosa. API key válida.\n";
                return true;
            } else {
                echo "La conexión fue exitosa pero la respuesta no contiene los datos esperados.\n";
                return false;
            }
        } catch (Exception $e) {
            echo "Error de conexión: " . $e->getMessage() . "\n";
            return false;
        }
    }
}

// Configuración
// Configuración
$provincias = [
    "Ciudad Autónoma de Buenos Aires"
];

$localidades = [
    "Ciudad Autónoma de Buenos Aires" => ["Recoleta", "Palermo", "San Telmo", "Puerto Madero", "Belgrano", "Caballito", "Núñez", "Villa Urquiza", "Villa Crespo", "Flores"]
];
$terminos_busqueda = [      
    "servicio técnico de notebooks",  
    "venta de notebooks nuevas",  
    "venta de notebooks usadas",  
    "venta de notebooks reacondicionadas",  
    "reparación de notebooks",  
    "mantenimiento de notebooks"
];


// Configuración de la API key (reemplazar por la clave real)
$api_key = "AIzaSyDLbhJaUuUO15qSArJWD2TNgOiHu4cLA9Q"; // Reemplazar con tu API key real

// Comprobar si se ha pasado un valor API key como argumento
if (isset($argv[1]) && !empty($argv[1])) {
    $api_key = $argv[1];
}

// Crear directorio de resultados si no existe
if (!file_exists('resultados')) {
    mkdir('resultados', 0777, true);
}

// Inicializar el objeto de scraping
$scraping = new ScrapingGoogleMaps($api_key, $provincias, $localidades, $terminos_busqueda);

// Verificar la conexión antes de proceder
if ($scraping->verificarConexionAPI()) {
    // Si tenemos un segundo argumento, es una provincia específica
    if (isset($argv[2]) && in_array($argv[2], $provincias)) {
        // Si tenemos un tercer argumento, es una localidad específica
        if (isset($argv[3]) && isset($localidades[$argv[2]]) && in_array($argv[3], $localidades[$argv[2]])) {
            $scraping->escanearUnaLocalidad($argv[2], $argv[3]);
        } else {
            // Escanear todas las localidades de una provincia
            $scraping->escanearLocalidadesProvincia($argv[2]);
        }
    } else {
        // Escanear todas las provincias y localidades
        $scraping->escanearTodasLasProvinciasYLocalidades();
    }
} else {
    echo "No se pudo verificar la conexión a la API. Verifique su API key y conexión a internet.\n";
    exit(1);
}