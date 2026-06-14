    </div><!-- /.content -->
  </div><!-- /.main -->
</div><!-- /.layout -->
<div class="lightbox-ov" id="lightbox"><img src="" alt=""></div>
<script>
  var side=document.getElementById('side'),bd=document.getElementById('bd'),bg=document.getElementById('burger');
  function _open(o){side.classList.toggle('open',o);bd.classList.toggle('show',o);}
  if(bg)bg.addEventListener('click',function(){_open(true);});
  if(bd)bd.addEventListener('click',function(){_open(false);});
  // Lightbox: tocar cualquier imagen .zoomable la agranda
  var _lb=document.getElementById('lightbox');
  document.addEventListener('click',function(e){
    if(e.target.tagName==='IMG' && e.target.classList.contains('zoomable')){
      _lb.querySelector('img').src=e.target.src; _lb.classList.add('show');
    } else if(e.target===_lb || e.target.parentNode===_lb){ _lb.classList.remove('show'); }
  });
</script>
</body>
</html>
