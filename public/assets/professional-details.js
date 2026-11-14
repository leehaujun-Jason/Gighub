'use strict';

{
    const current = document.getElementById('current_job');
    const end = document.getElementById('end_date');
    const synchronize = () => { end.disabled = current.checked; };
    current.addEventListener('change', synchronize);
    synchronize();
}
