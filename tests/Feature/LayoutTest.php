<?php

use App\Enums\UserRole;
use App\Models\User;

test('guests see the login and register links', function () {
    $view = $this->blade('<x-layouts::app>本文</x-layouts::app>');

    $view->assertSee('イベント一覧')
        ->assertSee('ログイン')
        ->assertSee('会員登録')
        ->assertDontSee('マイチケット')
        ->assertDontSee('ログアウト');
});

test('the navigation depends on the role', function (string $role, array $visible, array $hidden) {
    $this->actingAs(User::factory()->create(['role' => UserRole::from($role)]));

    $view = $this->blade('<x-layouts::app>本文</x-layouts::app>');

    foreach ($visible as $text) {
        $view->assertSee($text);
    }

    foreach ($hidden as $text) {
        $view->assertDontSee($text);
    }
})->with([
    'customer' => ['customer', ['イベント一覧', 'マイチケット', '設定', 'ログアウト'], ['主催者メニュー', 'イベント管理', 'ユーザー管理', '会員登録']],
    'organizer' => ['organizer', ['マイチケット', '主催者メニュー'], ['イベント管理', 'ユーザー管理']],
    'admin' => ['admin', ['マイチケット', 'イベント管理', 'ユーザー管理'], ['主催者メニュー']],
]);
