/*
 * Regola d'oro: una persona e' identificata dal suo id (campo nascosto js-pid),
 * mai dal nome. Il nome e' solo un'etichetta della scheda, condivisa da tutti i
 * corsi a cui la persona e' iscritta: se lo cambi, cambia ovunque. Da qui gli avvisi.
 */
(function () {
  'use strict';

  var norm = function (s) { return (s || '').trim().toLowerCase(); };

  /* ============ pagina Iscritti ============ */
  var form = document.getElementById('form-iscritti');
  if (form) {
    var rubrica = window.ANAGRAFICA || [];
    var sporco  = false;

    function campi(riga) {
      return {
        riga:    riga,
        cognome: riga.querySelector('.js-cognome'),
        nome:    riga.querySelector('.js-nome'),
        tel:     riga.querySelector('.js-tel'),
        cert:    riga.querySelector('.js-cert'),
        pid:     riga.querySelector('.js-pid'),
        nuova:   riga.querySelector('.js-nuova')
      };
    }

    function perId(id) {
      for (var i = 0; i < rubrica.length; i++) {
        if (rubrica[i].id === id) { return rubrica[i]; }
      }
      return null;
    }

    function perNome(cognome, nome) {
      return rubrica.filter(function (p) {
        return norm(p.cognome) === norm(cognome) && norm(p.nome) === norm(nome);
      });
    }

    function elencoCorsi(p) {
      return (p && p.altrove && p.altrove.length) ? p.altrove.join(', ') : '';
    }

    function marca(c, testo) {
      var box = c.riga.querySelector('.js-nota');
      if (!box) { return; }
      box.textContent = testo || '';
      box.hidden = !testo;
    }

    /* valori di partenza: servono per capire se il nome e' stato cambiato */
    var righe = [].slice.call(form.querySelectorAll('tr.riga'));
    righe.forEach(function (r) {
      var c = campi(r);
      r.dataset.origCognome = c.cognome.value;
      r.dataset.origNome    = c.nome.value;
      r.dataset.origPid     = c.pid.value;
    });

    /* riga senza persona collegata: aggancio la scheda esistente o ne creo una nuova */
    function risolviNuova(c) {
      var cognome = c.cognome.value.trim(), nome = c.nome.value.trim();
      if (!cognome || !nome) { return; }
      var trovate = perNome(cognome, nome);
      if (trovate.length !== 1) { return; }
      var p = trovate[0];

      var telDiverso = c.tel.value.trim() !== '' && norm(c.tel.value) !== norm(p.telefono);
      if (telDiverso) {
        var q = 'In anagrafica esiste già ' + p.cognome + ' ' + p.nome
              + (p.telefono ? ' con telefono ' + p.telefono : ' senza telefono')
              + ', tu hai scritto ' + c.tel.value.trim() + '.\n\n'
              + 'OK = è la stessa persona, aggiorno la sua scheda.\n'
              + 'Annulla = è un\'altra persona, creo una scheda separata.';
        if (!confirm(q)) {
          c.nuova.value = '1';
          c.pid.value   = '0';
          marca(c, 'nuova scheda separata');
          return;
        }
      }
      c.pid.value   = p.id;
      c.nuova.value = '';
      if (!c.tel.value)  { c.tel.value  = p.telefono || ''; }
      if (!c.cert.value) { c.cert.value = p.cert || ''; }
      marca(c, elencoCorsi(p) ? 'scheda condivisa con: ' + elencoCorsi(p) : 'scheda già in anagrafica');
    }

    /* riga con persona collegata a cui e' stato cambiato il nome */
    function risolviRinomina(c) {
      var pid = parseInt(c.pid.value, 10) || 0;
      var p   = perId(pid);
      if (!p) { return; }

      var cognome = c.cognome.value.trim(), nome = c.nome.value.trim();
      if (norm(cognome) === norm(p.cognome) && norm(nome) === norm(p.nome)) {
        c.nuova.value = '';
        marca(c, elencoCorsi(p) ? 'scheda condivisa con: ' + elencoCorsi(p) : '');
        return;
      }
      if (!cognome || !nome) { return; }   // sta ancora scrivendo

      var vecchio = p.cognome + ' ' + p.nome;
      var nuovo   = cognome + ' ' + nome;

      // il nome digitato e' quello di un'altra scheda esistente: e' un cambio di persona
      var altre = perNome(cognome, nome);
      if (altre.length === 1 && altre[0].id !== p.id) {
        if (confirm('Al posto di ' + vecchio + ' hai messo ' + nuovo + ', che ha già una scheda in anagrafica.\n\n'
                  + 'OK = uso la sua scheda (' + vecchio + ' viene disiscritta da questo corso).\n'
                  + 'Annulla = ripristino ' + vecchio + '.')) {
          c.pid.value   = altre[0].id;
          c.nuova.value = '';
          c.tel.value   = altre[0].telefono || '';
          c.cert.value  = altre[0].cert || '';
          marca(c, 'collegata alla scheda di ' + nuovo);
        } else {
          ripristina(c);
        }
        return;
      }

      // la persona compare anche in altri corsi: il nome e' condiviso
      if (p.altrove && p.altrove.length) {
        if (confirm('Stai cambiando il nome della scheda di ' + vecchio + ', che è iscritta anche a:\n\n'
                  + '• ' + p.altrove.join('\n• ') + '\n\n'
                  + 'È la stessa persona in tutti i corsi, quindi diventerà ' + nuovo + ' anche lì.\n\n'
                  + 'OK = rinomino ovunque.\n'
                  + 'Annulla = ripristino (per mettere un\'altra persona su questa riga usa il tasto ✕).')) {
          marca(c, 'rinomina anche in: ' + p.altrove.join(', '));
        } else {
          ripristina(c);
        }
        return;
      }

      // persona presente solo qui: correzione o sostituzione?
      if (!confirm('Questa riga era ' + vecchio + ' e ora è ' + nuovo + '.\n\n'
                 + 'OK = stessa persona, correggo la sua scheda.\n'
                 + 'Annulla = è un\'altra persona, creo una scheda nuova (' + vecchio + ' resta in anagrafica).')) {
        c.nuova.value = '1';
        marca(c, 'nuova scheda separata');
      } else {
        c.nuova.value = '';
        marca(c, 'scheda di ' + vecchio + ' rinominata');
      }
    }

    function ripristina(c) {
      c.cognome.value = c.riga.dataset.origCognome;
      c.nome.value    = c.riga.dataset.origNome;
      c.pid.value     = c.riga.dataset.origPid;
      c.nuova.value   = '';
      marca(c, '');
    }

    form.addEventListener('change', function (e) {
      var t = e.target;
      sporco = true;
      if (!t.classList.contains('js-cognome') && !t.classList.contains('js-nome')) { return; }
      var c = campi(t.closest('tr'));
      if (parseInt(c.pid.value, 10) > 0) { risolviRinomina(c); } else { risolviNuova(c); }
      c.riga.classList.toggle('vuota', !c.cognome.value && !c.nome.value);
      c.riga.classList.toggle('piena', !!(c.cognome.value || c.nome.value));
    });

    form.addEventListener('input', function () { sporco = true; });

    form.addEventListener('click', function (e) {
      var b = e.target.closest('.js-svuota');
      if (!b) { return; }
      var c = campi(b.closest('tr'));
      c.cognome.value = ''; c.nome.value = ''; c.tel.value = ''; c.cert.value = '';
      c.pid.value = '0'; c.nuova.value = '';
      c.riga.classList.remove('piena');
      c.riga.classList.add('vuota');
      marca(c, '');
      sporco = true;
      c.cognome.focus();
    });

    form.addEventListener('submit', function () { sporco = false; });
    window.addEventListener('beforeunload', function (e) {
      if (sporco) { e.preventDefault(); e.returnValue = ''; }
    });
  }

  /* ============ scheda anagrafica ============ */
  var fp = document.getElementById('form-persona');
  if (fp) {
    var oc = document.getElementById('f-cognome');
    var on = document.getElementById('f-nome');
    if (oc && on) {
      var vc = oc.value, vn = on.value;
      fp.addEventListener('submit', function (e) {
        var cambiato = norm(oc.value) !== norm(vc) || norm(on.value) !== norm(vn);
        var quanti   = parseInt(fp.dataset.iscrizioni, 10) || 0;
        if (!cambiato || quanti < 2) { return; }
        var msg = 'Stai rinominando ' + vc + ' ' + vn + ' in ' + oc.value + ' ' + on.value + '.\n\n'
                + 'La scheda è collegata a ' + quanti + ' corsi:\n• ' + (fp.dataset.corsi || '').split(', ').join('\n• ')
                + '\n\nIl nuovo nome comparirà in tutti. Continuare?';
        if (!confirm(msg)) { e.preventDefault(); }
      });
    }
  }
})();
