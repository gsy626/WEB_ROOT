/** @type {HTMLCanvasElement} */
const canvas=document.getElementById("canvas");
const ctx= canvas.getContext("2d");
const BackColor="rgba(0,0,0,0.05)";
const Balls=Array();
const LEFT=2;
const DELTA_T=1;
function Clear_all()
{
	ctx.fillStyle=BackColor;
	ctx.fillRect(0,0,canvas.width,canvas.height);
}
function Draw_ball(x,y,r,color,alpha)
{
	ctx.globalAlpha=alpha;
	ctx.fillStyle=color;
	ctx.beginPath();
	ctx.moveTo(x, y);
	ctx.arc(x, y, r, 0, Math.PI*2);
	ctx.closePath();
	ctx.fill();
	ctx.globalAlpha=1;
}
function Init_balls()
{
	for(let i=0;i<BallNum;i++)
	{
		let radius=Math.random() * MaxRadius + 1;
		let color=`rgb(${Math.random() * 255},${Math.random() * 255},${Math.random() * 255})`;
		let v_x=Math.random()*MaxV+1;
		let v_y=Math.random()*MaxV+1;
		Balls[i]={v_x:v_x,v_y:v_y,x:0,y:0,color:color,radius:radius};
	}
}
function Main()
{
	Clear_all();
	for(let i=0;i<Balls.length;i++)
	{	
		Balls[i].x+=(Balls[i].v_x * DELTA_T);
		Balls[i].y+=(Balls[i].v_y * DELTA_T);
		Draw_ball(Balls[i].x,Balls[i].y,Balls[i].radius,Balls[i].color,1);
	}
	let MinX=Math.min(...Balls.map(ball => ball.x));
	let MinY=Math.min(...Balls.map(ball => ball.y));
	if(MinX >= (canvas.width) || MinY >= (canvas.height))Init_balls();
}
const MaxRadius=50;
const MaxV=20;
const BallNum=50;
Init_balls();
setInterval(Main,DELTA_T);
