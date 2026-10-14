var navBar=document.getElementById("nav-bar");
if(navBar){
	const items = [
		{text:"首页",href:"/"},
		{text:"关于",href:"/content/about.html"},
		];
	items.forEach(item =>
		{
			const a=document.createElement("a");
			a.className="navItem";
			a.href=item.href;
			a.textContent=item.text;
			navBar.appendChild(a);
		}
		);
}

