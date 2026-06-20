@extends('admin.game.layout')

@section('content')
  <p class="hint">Вопросы финального опроса и варианты ответов. Минимум 2 варианта на вопрос. Изменения применяются ко всем новым прохождениям.</p>
  <form method="POST" action="{{ route('admin.game.survey.update') }}" id="survey-form">
    @csrf
    @method('PUT')
    <div id="questions"></div>
    <button type="button" class="btn ghost" onclick="addQuestion()">+ Добавить вопрос</button>
    <button type="submit" class="btn">Сохранить опрос</button>
  </form>

  <script>
  const SURVEY = @json($survey);
  const wrap = document.getElementById('questions');

  function optRow(val){
    const d = document.createElement('div'); d.className = 'opt-row';
    const inp = document.createElement('input'); inp.type = 'text'; inp.className = 'opt'; inp.placeholder = 'Вариант ответа'; inp.value = val || '';
    const btn = document.createElement('button'); btn.type = 'button'; btn.className = 'x'; btn.textContent = '×';
    btn.onclick = function(){ d.remove(); reindex(); };
    d.appendChild(inp); d.appendChild(btn);
    return d;
  }

  function qBlock(q){
    q = q || { id:'', question:'', options:['',''] };
    const d = document.createElement('div'); d.className = 'q-block';
    const head = document.createElement('div'); head.className = 'q-head';
    const title = document.createElement('span'); title.className = 'q-title';
    const del = document.createElement('button'); del.type = 'button'; del.className = 'x'; del.textContent = 'Удалить вопрос';
    del.onclick = function(){ d.remove(); reindex(); };
    head.appendChild(title); head.appendChild(del); d.appendChild(head);

    const idInp = document.createElement('input'); idInp.type = 'hidden'; idInp.className = 'qid'; idInp.value = q.id || '';
    d.appendChild(idInp);
    const qText = document.createElement('input'); qText.type = 'text'; qText.className = 'qtext'; qText.placeholder = 'Текст вопроса'; qText.value = q.question || '';
    d.appendChild(qText);

    const opts = document.createElement('div'); opts.className = 'opts';
    (q.options && q.options.length ? q.options : ['','']).forEach(o => opts.appendChild(optRow(o)));
    d.appendChild(opts);

    const addBtn = document.createElement('button'); addBtn.type = 'button'; addBtn.className = 'addopt'; addBtn.textContent = '+ вариант';
    addBtn.onclick = function(){ opts.appendChild(optRow('')); reindex(); };
    d.appendChild(addBtn);
    return d;
  }

  function addQuestion(){ wrap.appendChild(qBlock()); reindex(); }

  function reindex(){
    Array.from(wrap.querySelectorAll('.q-block')).forEach((b, i) => {
      b.querySelector('.q-title').textContent = 'Вопрос ' + (i + 1);
      b.querySelector('.qid').name = 'questions[' + i + '][id]';
      b.querySelector('.qtext').name = 'questions[' + i + '][question]';
      Array.from(b.querySelectorAll('.opt')).forEach(o => o.name = 'questions[' + i + '][options][]');
    });
  }

  (SURVEY.length ? SURVEY : [null]).forEach(q => wrap.appendChild(qBlock(q)));
  reindex();
  </script>
@endsection
