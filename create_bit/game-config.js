(function () {
  "use strict";

  window.MERGE_GAME_CONFIG = {
    id: "bit-merge",
    assetBase: "assets/levels/",
    spawnLevelCount: 5,
    storageKey: "bit-merge:best:v1",

    ui: {
      title: "合成北理工",
      description: "北京理工大学校园图片合成小游戏：拖动图片，合成 xiao_hui 校徽。"
    },

    // 前 8 级来自 pictures/1.jpg–8.png，最高一级使用 pictures/xiao_hui.jpg。
    levels: [
      { radius: 14, image: "level-1.jpg" },
      { radius: 18, image: "level-2.jpg" },
      { radius: 23, image: "level-3.jpg" },
      { radius: 29, image: "level-4.jpg" },
      { radius: 36, image: "level-5.jpg" },
      { radius: 45, image: "level-6.jpg" },
      { radius: 56, image: "level-7.jpg" },
      { radius: 70, image: "level-8.jpg" },
      { radius: 88, image: "xiao_hui.jpg" }
    ]
  };
}());
