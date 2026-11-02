<?php

// Configuración propia de SULA Core
return [
    'version' => '0.5.0',

    // Quién firma los cambios en el journal (temporal, hasta tener login)
    'usuario' => env('SULA_USUARIO', 'desarrollo'),

    // Módulo 3: de dónde salen los planos
    'planos' => [
        'fuente' => env('PLANOS_FUENTE', 'local'),                         // drive o local
        'drive_credenciales' => env('PLANOS_DRIVE_CREDENCIALES', 'datos-privados/drive-service-account.json'),
        'drive_carpeta' => env('PLANOS_DRIVE_CARPETA'),                    // id de la carpeta raíz en Drive
        // Con qué cuenta se crean carpetas y se suben PDFs: cuenta_conectada ("Conectar Google") o cuenta_servicio
        'drive_escribir_con' => env('PLANOS_DRIVE_ESCRIBIR_CON', 'cuenta_conectada'),
        // Con ?: porque una variable vacía en el .env llega como '', no como "sin definir"
        'carpeta_local' => env('PLANOS_CARPETA_LOCAL') ?: base_path('datos-privados/planos'),
        'patron_clave' => env('PLANOS_PATRON_CLAVE'),                      // vacío = 2-1-0072-0003
        // Tamaño de cada envío a la IA. Los valores por defecto caben en el plan GRATIS de Groq
        // (6,000 tokens por minuto). Con plan de pago puedes subirlos (12000 y 3).
        'caracteres_por_lote' => (int) env('PLANOS_CARACTERES_POR_LOTE', 5000),
        'imagenes_por_lote' => (int) env('PLANOS_IMAGENES_POR_LOTE', 1),
        // Merma que suma el PDF del despiece al calcular cuánto material pedir (0.10 = 10%)
        'merma_pdf' => env('PLANOS_MERMA_PDF') ?: '0.10',
    ],

    // "Conectar Google" (OAuth): para escribir en el Drive de una persona
    'google' => [
        'client_id' => env('GOOGLE_OAUTH_CLIENT_ID'),
        'client_secret' => env('GOOGLE_OAUTH_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_OAUTH_REDIRECT', 'http://localhost:8000/google/callback'),
    ],

    // IA: Groq (API compatible con OpenAI)
    'groq' => [
        'api_key' => env('GROQ_API_KEY'),
        'modelo_texto' => env('GROQ_MODELO_TEXTO', 'openai/gpt-oss-120b'),
        'modelo_vision' => env('GROQ_MODELO_VISION', 'qwen/qwen3.8-27b'),
        'max_tokens' => (int) env('GROQ_MAX_TOKENS', 3000),
        'max_tokens_vision' => (int) env('GROQ_MAX_TOKENS_VISION', 2000),
    ],
];
