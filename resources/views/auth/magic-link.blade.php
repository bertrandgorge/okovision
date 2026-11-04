<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Connexion – {{ config('app.name') }}</title>
<link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
<style>
  :root { --bg:#fafaf9; --card:#fff; --text:#1c1917; --muted:#78716c; --border:#e7e5e4; --accent:#f97316; --accent-hover:#ea580c; color-scheme: light; }
  @media (prefers-color-scheme: dark) { :root { --bg:#0c0a09; --card:#1c1917; --text:#f5f5f4; --muted:#a8a29e; --border:#292524; color-scheme: dark; } }
  body { margin:0; min-height:100vh; display:grid; place-items:center; background:var(--bg); color:var(--text); font:16px/1.5 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif; padding:16px; box-sizing:border-box; }
  .card { width:100%; max-width:420px; background:var(--card); border:1px solid var(--border); border-radius:16px; padding:32px; box-sizing:border-box; text-align:center; }
  h1 { font-size:1.4rem; margin:16px 0 8px; }
  p { color:var(--muted); margin:0 0 24px; }
  button, .btn { display:block; width:100%; padding:12px; border:0; border-radius:10px; background:var(--accent); color:#fff; font:600 1rem system-ui,sans-serif; cursor:pointer; text-decoration:none; box-sizing:border-box; }
  button:hover, .btn:hover { background:var(--accent-hover); }
  img { width:48px; height:48px; }
</style>
</head>
<body>
<main class="card">
  <img src="{{ asset('favicon.svg') }}" alt="">
  @if ($user)
    <h1>Connexion à {{ config('app.name') }}</h1>
    <p>Bonjour {{ $user->name }}, cliquez pour vous connecter. Vous resterez connecté sur cet appareil.</p>
    <form method="post" action="{{ route('magic-link.login', ['token' => $token]) }}">
      @csrf
      <button type="submit" autofocus>Se connecter</button>
    </form>
  @else
    <h1>Lien expiré ou déjà utilisé</h1>
    <p>Les liens de connexion ne fonctionnent qu'une fois et pendant {{ \App\Auth\MagicLink::TTL_MINUTES }} minutes. Demandez-en un nouveau.</p>
    <a class="btn" href="{{ filament()->getPanel('admin')->getLoginUrl() }}">Recevoir un nouveau lien</a>
  @endif
</main>
</body>
</html>
