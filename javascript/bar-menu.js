//off screen menu
const barMenu = document.querySelector(".bar-menu");
const offScreenMenu = document.querySelector(".off-screen-menu");

barMenu.addEventListener('click', () => {
    barMenu.classList.toggle('active');
    offScreenMenu.classList.toggle('active');
})