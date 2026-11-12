<?php

namespace App\Livewire;

use Livewire\Component;

class Lonceng extends Component
{
    /** Buka satu notifikasi milik sendiri: tandai dibaca lalu pindah ke halamannya. */
    public function buka(string $id)
    {
        $n = auth()->user()->notifications()->whereKey($id)->first();
        if (! $n) {
            return null;
        }

        $n->markAsRead();

        // Hanya path internal yang boleh dituju
        $url = (string) ($n->data['url'] ?? '/');
        if (! str_starts_with($url, '/') || str_starts_with($url, '//')) {
            $url = '/';
        }

        return $this->redirect($url);
    }

    public function tandaiSemua(): void
    {
        auth()->user()->unreadNotifications()->update(['read_at' => now()]);
    }

    public function render()
    {
        $user = auth()->user();

        return view('livewire.lonceng', [
            'jumlah' => $user->unreadNotifications()->count(),
            'daftar' => $user->notifications()->latest()->limit(10)->get(),
        ]);
    }
}
