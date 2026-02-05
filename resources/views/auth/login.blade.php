@extends('layouts.app')

@section('content')
    {{-- Landing page: full-bleed background image + overlay --}}
    <div class="fixed inset-0 z-0 bg-cover bg-center" style="background-image: url('https://industrialwebapps.com/wp-content/uploads/2021/01/MangProjectsmall-1-scaled.jpeg');" aria-hidden="true"></div>
    <div class="fixed inset-0 z-0 bg-black/50" aria-hidden="true"></div>
    <div class="relative z-10 flex min-h-screen flex-col justify-center px-4 py-12 sm:px-6 lg:px-8">
        <div class="mx-auto w-full max-w-md">
            {{-- Card --}}
            <div class="rounded-3xl border-0 bg-white p-8 shadow-xl sm:p-10">
                {{-- Title & subtitle --}}
                <div class="flex flex-col items-center justify-center text-center">
                    <img src="https://sindbad.tech/assets-landing/images-sindbad/logo.png" alt="Sindbad.Tech" class="mx-auto h-auto w-full max-w-[200px] sm:max-w-[240px]" />
                    <p class="mt-2 text-sm font-bold text-gray-500 sm:text-base">Project Management Tool</p>
                    <p class="mt-0.5 text-sm font-bold text-gray-500 sm:text-base" dir="rtl">أداة إدارة المشاريع</p>
                </div>

                @if ($errors->any())
                    <div class="mt-6 rounded-xl bg-red-50 px-4 py-3 text-sm text-red-800 ring-1 ring-red-200">
                        <ul class="list-disc space-y-1 pl-5">
                            @foreach ($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <div class="mt-8">
                    @if (config('services.microsoft.client_id'))
                        <a
                            href="{{ route('auth.microsoft') }}"
                            class="flex w-full items-center justify-center gap-3 rounded-xl bg-[#0078D4] px-4 py-3.5 text-sm font-semibold text-white shadow-md transition hover:bg-[#106EBE] focus:outline-none focus:ring-2 focus:ring-[#0078D4] focus:ring-offset-2"
                        >
                            <svg class="h-5 w-5 flex-shrink-0" viewBox="0 0 21 21" fill="none" aria-hidden="true">
                                <rect x="1" y="1" width="9" height="9" fill="#F25022"/>
                                <rect x="11" y="1" width="9" height="9" fill="#7FBA00"/>
                                <rect x="1" y="11" width="9" height="9" fill="#00A4EF"/>
                                <rect x="11" y="11" width="9" height="9" fill="#FFB900"/>
                            </svg>
                            Sign in with Microsoft
                        </a>
                    @else
                        <div class="rounded-xl border border-amber-200 bg-amber-50/80 px-4 py-4 text-sm text-amber-900 ring-1 ring-amber-200/60">
                            <p class="font-medium">Microsoft SSO is not configured</p>
                            <p class="mt-1.5 text-amber-800/90">Set <code class="rounded bg-amber-100 px-1 py-0.5 font-mono text-xs">MICROSOFT_CLIENT_ID</code> and <code class="rounded bg-amber-100 px-1 py-0.5 font-mono text-xs">MICROSOFT_CLIENT_SECRET</code> in your <code class="rounded bg-amber-100 px-1 py-0.5 font-mono text-xs">.env</code> file.</p>
                        </div>
                    @endif
                </div>
            </div>

            <p class="mt-8 text-center text-xs text-gray-400">PMT · SindbadTech</p>
        </div>
    </div>
@endsection
