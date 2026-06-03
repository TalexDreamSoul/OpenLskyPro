<x-guest-layout>
    <x-auth-card>
        <x-slot name="logo">
            <a href="/">
                <x-application-logo class="w-20 h-20 fill-current text-gray-500 text-4xl" />
            </a>
        </x-slot>

        <div class="mb-6">
            <h2 class="text-lg font-semibold text-gray-800">完成 Casdoor 登录</h2>
            <p class="mt-2 text-sm text-gray-600">
                Casdoor 账号尚未绑定本地账号。你可以创建新账号，或输入已有本地账号密码完成绑定。
            </p>
        </div>

        <x-auth-validation-errors class="mb-4" :errors="$errors" />

        <div class="mb-6 rounded-md bg-gray-50 p-4 text-sm text-gray-700">
            <div><span class="font-semibold">昵称：</span>{{ $identity['name'] ?: '未提供' }}</div>
            <div class="mt-1"><span class="font-semibold">邮箱：</span>{{ $identity['email'] ?: '未提供' }}</div>
            <div class="mt-1">
                <span class="font-semibold">邮箱验证：</span>
                @if($identity['email_verified'] ?? false)
                    <span class="text-green-600">已验证</span>
                @else
                    <span class="text-red-600">未验证或未提供</span>
                @endif
            </div>
        </div>

        <div class="space-y-6">
            <div>
                <h3 class="text-sm font-semibold text-gray-800">创建新账号</h3>
                @if(! $registrationEnabled)
                    <p class="mt-2 text-sm text-red-600">站点管理员关闭了注册功能，请绑定已有账号。</p>
                @elseif(! $canCreate)
                    <p class="mt-2 text-sm text-red-600">Casdoor 未返回已验证邮箱，不能直接创建账号。</p>
                @else
                    <form class="mt-3" method="POST" action="{{ route('casdoor.create') }}">
                        @csrf
                        <div>
                            <x-label for="name" value="昵称" />
                            <x-input id="name" class="block mt-1 w-full" type="text" name="name" :value="old('name', $identity['name'] ?: \Illuminate\Support\Str::before($identity['email'], '@'))" required />
                        </div>
                        <x-button class="mt-3 w-full justify-center">
                            创建并登录
                        </x-button>
                    </form>
                @endif
            </div>

            <div class="border-t pt-6">
                <h3 class="text-sm font-semibold text-gray-800">绑定已有账号</h3>
                <form class="mt-3" method="POST" action="{{ route('casdoor.bind') }}">
                    @csrf

                    <div>
                        <x-label for="email" :value="__('Email')" />
                        <x-input id="email" class="block mt-1 w-full" type="email" name="email" :value="old('email')" required autofocus />
                    </div>

                    <div class="mt-4">
                        <x-label for="password" :value="__('Password')" />
                        <x-input id="password" class="block mt-1 w-full" type="password" name="password" required autocomplete="current-password" />
                    </div>

                    <x-button class="mt-3 w-full justify-center">
                        绑定并登录
                    </x-button>
                </form>
            </div>
        </div>
    </x-auth-card>
</x-guest-layout>
