(function(){
  function setCookie(n, v, d){ var e=new Date(); e.setTime(e.getTime()+d*24*60*60*1000); document.cookie=n+"="+encodeURIComponent(v)+";path=/;expires="+e.toUTCString(); }
  function onSelect(lang){ try { setCookie('vl_lang', lang, 365); console.log('[VL-LAS] language set:', lang); } catch(e){} }
  document.addEventListener('click', function(e){
    var a = e.target.closest('.vl-las-lang'); if(!a) return;
    e.preventDefault(); onSelect(a.getAttribute('data-lang')||'');
  });
  var dd = document.querySelector('.vl-las-lang-dd');
  if (dd) { dd.addEventListener('change', function(){ onSelect(dd.value); }); }
})();
