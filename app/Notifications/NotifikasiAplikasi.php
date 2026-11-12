<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

/**
 * Notifikasi di dalam aplikasi (ikon lonceng). Satu kelas untuk semua jenis;
 * perbedaannya ada di $jenis dan teksnya.
 *
 * $ref dipakai untuk mencegah notifikasi ganda dan untuk menandai dibaca
 * otomatis saat transaksinya sudah diproses (contoh: "pembayaran:12").
 */
class NotifikasiAplikasi extends Notification
{
    public function __construct(
        public string $jenis,
        public string $judul,
        public string $pesan,
        public string $url,
        public string $ref,
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'jenis' => $this->jenis,
            'judul' => $this->judul,
            'pesan' => $this->pesan,
            'url' => $this->url,
            'ref' => $this->ref,
        ];
    }
}
