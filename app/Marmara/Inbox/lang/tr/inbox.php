<?php

declare(strict_types=1);

return [
    'title' => 'Gelen Kutusu',
    'description' => 'Kanallarınıza gelen yorumlar ve mesajlar tek yerde.',
    'filters' => [
        'status' => 'Göster',
        'open' => 'Gelen kutusu',
        'unread' => 'Okunmamış',
        'archived' => 'Arşiv',
        'all' => 'Tümü',
        'platform' => 'Ağ',
        'all_platforms' => 'Tüm ağlar',
        'kind' => 'Tür',
        'all_kinds' => 'Yorumlar ve mesajlar',
    ],
    'kinds' => [
        'comment' => 'Yorum',
        'message' => 'Mesaj',
    ],
    'list' => [
        'empty_title' => 'Henüz bir şey yok',
        'empty_body' => 'Kanallarınıza gelen yeni yorumlar ve mesajlar birkaç dakika içinde burada görünür.',
        'no_results' => 'Bu filtrelere uyan yorum ya da mesaj yok. Başka bir ağ veya tür seçin.',
        'unknown_author' => 'Biri',
        'linkedin_member' => 'LinkedIn üyesi',
        'hidden' => 'Gizlendi',
    ],
    'thread' => [
        'select' => 'Konuşmayı okumak için bir yorum ya da mesaj seçin.',
        'on_post' => 'Gönderinizde',
        'open_on_network' => 'Ağda aç',
        'earlier' => 'Bu konuşmada daha önce',
        'your_replies' => 'Yanıtlarınız',
        'reply_failed' => 'Gönderilemedi',
        'reply_sending' => 'Gönderiliyor',
    ],
    'reply' => [
        'placeholder' => 'Yanıt yazın',
        'send' => 'Yanıtı gönder',
        'window_ends' => ':time saatine kadar yanıtlayabilirsiniz.',
        'window_closed' => 'Bu konuşmanın yanıt süresi doldu. Kişi size yeniden yazdığında yanıtlayabilirsiniz.',
        'not_allowed' => 'Bu öğe TryPost\'tan yanıtlanamıyor.',
        'no_permission' => 'Yalnızca doğrudan yayınlayabilen üyeler yanıtlayabilir.',
    ],
    'actions' => [
        'mark_read' => 'Okundu yap',
        'mark_unread' => 'Okunmadı yap',
        'archive' => 'Arşivle',
        'unarchive' => 'Gelen kutusuna taşı',
        'hide' => 'Yorumu gizle',
    ],
    'channels' => [
        'title' => 'Kanallar',
        'needs_permission' => 'İzin gerekiyor',
        'statuses' => [
            'pending' => 'İlk kontrol bekleniyor',
            'ok' => 'Güncel',
            'needs_permission' => 'İzin gerekiyor',
            'rate_limited' => 'Ağın limiti nedeniyle duraklatıldı',
            'authentication' => 'Kanalı yeniden bağlayın',
            'unsupported' => 'Kullanılamıyor',
            'error' => 'Okunamadı',
        ],
    ],
    'errors' => [
        'window_closed' => 'Bu konuşmanın yanıt süresi doldu.',
        'too_long' => 'Yanıtınız çok uzun: :detail',
        'cannot_reply' => 'Bu yanıt gönderilemiyor: :detail',
        'send_failed' => 'Ağ yanıtı kabul etmedi. Birkaç dakika sonra yeniden deneyin.',
        'hide_unavailable' => 'Bu yorum TryPost\'tan gizlenemiyor.',
        'hide_failed' => 'Ağ yorumu gizlemedi. Birkaç dakika sonra yeniden deneyin.',
    ],
];
