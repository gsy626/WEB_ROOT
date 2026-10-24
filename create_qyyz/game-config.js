(function () {
  "use strict";

  window.MERGE_GAME_CONFIG = {
    id: "qyyz-merge",
    assetBase: "assets/levels/",
    spawnLevelCount: 5,
    storageKey: "qyyz-merge:best:v1",

    ui: {
      title: "合成清苑一中",
      description: "河北省保定市清苑区第一中学主题图片合成小游戏：拖动图片，合成最高等级。"
    },

    // 17 级都来自原游戏 1.jpg–17.jpg，已统一裁成 512×512 方形素材。
    // 半径严格递增，最大 130 小于半个画板宽度（200）。
    levels: [
      { radius: 14, image: "level-1.jpg" },
      { radius: 16, image: "level-2.jpg" },
      { radius: 18, image: "level-3.jpg" },
      { radius: 21, image: "level-4.jpg" },
      { radius: 24, image: "level-5.jpg" },
      { radius: 28, image: "level-6.jpg" },
      { radius: 32, image: "level-7.jpg" },
      { radius: 37, image: "level-8.jpg" },
      { radius: 43, image: "level-9.jpg" },
      { radius: 49, image: "level-10.jpg" },
      { radius: 56, image: "level-11.jpg" },
      { radius: 65, image: "level-12.jpg" },
      { radius: 74, image: "level-13.jpg" },
      { radius: 86, image: "level-14.jpg" },
      { radius: 98, image: "level-15.jpg" },
      { radius: 113, image: "level-16.jpg" },
      { radius: 130, image: "level-17.jpg" }
    ]
  };
}());
