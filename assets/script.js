document.addEventListener('DOMContentLoaded', function(){
  const input = document.getElementById('photoInput');
  const preview = document.getElementById('preview');
  if (!input) return;
  input.addEventListener('change', function(){
    preview.innerHTML = '';
    const f = input.files && input.files[0];
    if (!f) return;
    const reader = new FileReader();
    reader.onload = function(e){
      const img = document.createElement('img');
      img.src = e.target.result;
      preview.appendChild(img);
    };
    reader.readAsDataURL(f);
  });

  // スライダーの値を output に反映
  const selfRange = document.getElementById('selfRange');
  const selfRangeValue = document.getElementById('selfRangeValue');
  if (selfRange && selfRangeValue) {
    const updateOutput = () => {
      // output 要素は textContent または value のどちらでも表示されるが、両方更新しておく
      selfRangeValue.value = selfRange.value;
      selfRangeValue.textContent = selfRange.value;
    };
    // 初期表示
    updateOutput();
    // 入力イベントでリアルタイム更新
    selfRange.addEventListener('input', updateOutput);
    // 変更イベントもフォールバックとして追加
    selfRange.addEventListener('change', updateOutput);
  }
});
