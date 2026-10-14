$env:Path += ";C:\php"
# La document root e' public/, come sul server: i dati restano in ..\dati
php -S localhost:8000 -t "$PSScriptRoot\public"
