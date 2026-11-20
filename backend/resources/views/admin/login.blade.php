<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Admin login - Asmrandle</title>
    <style>
        body { background: #171922; color: #f4f4f6; font: 16px system-ui, sans-serif; margin: 0; }
        main { max-width: 24rem; margin: 10vh auto; padding: 2rem; }
        label, input { display: block; width: 100%; box-sizing: border-box; }
        label { margin-top: 1rem; }
        input { margin-top: .4rem; padding: .7rem; font: inherit; }
        button { margin-top: 1.5rem; padding: .7rem 1.2rem; font: inherit; cursor: pointer; }
        .error { color: #ffaaa7; }
        a { color: #a9cbff; }
    </style>
</head>
<body>
<main>
    <h1>Admin login</h1>
    @if ($errors->any())
        <p class="error" role="alert">{{ $errors->first() }}</p>
    @endif
    <form method="post" action="{{ route('admin.login', absolute: false) }}">
        @csrf
        <label for="username">Username</label>
        <input id="username" name="username" value="{{ old('username') }}" autocomplete="username" required maxlength="64">
        <label for="password">Password</label>
        <input id="password" name="password" type="password" autocomplete="current-password" required maxlength="72">
        <button type="submit">Log in</button>
    </form>
    <p><a href="/">Back to game</a></p>
</main>
</body>
</html>
