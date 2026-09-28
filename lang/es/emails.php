<?php

/*
|--------------------------------------------------------------------------
| Correos entrantes (bandeja "Correos entrantes")
|--------------------------------------------------------------------------
| Solo las llaves que necesita el comando `emails:ingest` en esta rebanada de la funcionalidad. Slices
| posteriores (vistas, notificaciones) añaden más llaves a este mismo archivo bajo sus propios grupos.
*/

return [
    'command' => [
        'done' => 'Correos revisados: :fetched. Nuevos: :created. Duplicados: :duplicate. '
            .'Autorespuestas omitidas: :skipped_autoresponder. Rechazados: :skipped_invalid. Fallidos: :failed.',
    ],
    'errors' => [
        'already_reviewed' => 'Este correo ya fue revisado (descartado o convertido); no se puede volver a descartar ni convertir.',
        'too_many_attachments_to_convert' => 'Este correo tiene mas adjuntos de los que la actividad puede recibir (maximo :max). Elimina alguno antes de convertirlo.',
    ],
    'index' => [
        'columns' => [
            'sender' => 'Remitente',
            'subject' => 'Asunto',
            'received_at' => 'Recibido',
            'status' => 'Estado',
        ],
        'attachments_count' => '{1} 1 adjunto|[2,*] :count adjuntos',
        'empty' => [
            'pending_review' => 'No hay correos pendientes de revisión.',
            'converted' => 'No hay correos convertidos.',
            'discarded' => 'No hay correos descartados.',
            'all' => 'No hay correos entrantes.',
        ],
    ],
    'show' => [
        'back' => 'Volver a la bandeja',
        'sender' => 'Remitente',
        'subject' => 'Asunto',
        'received_at' => 'Recibido',
        'status' => 'Estado',
        'body' => 'Cuerpo del correo',
        'attachments' => 'Adjuntos',
        'no_attachments' => 'Este correo no tiene adjuntos.',
        'converted_info' => 'Este correo se convirtió en la actividad:',
        'reviewed_by' => 'Revisado por',
        'reviewed_at' => 'Revisado el',
        'discard_reason' => 'Motivo del descarte',
        'convert_button' => 'Convertir a actividad',
        'discard_button' => 'Descartar',
        'discard_confirm' => 'Vas a descartar este correo. El motivo queda registrado y ya no podrás convertirlo.',
        'discard_reason_label' => 'Motivo',
    ],
    'convert' => [
        'title' => 'Convertir correo en actividad',
        'source_summary' => 'Correo de origen',
        'sender' => 'Remitente',
        'received_at' => 'Recibido',
        'submit' => 'Convertir en actividad',
        'cancel' => 'Cancelar',
    ],
];
