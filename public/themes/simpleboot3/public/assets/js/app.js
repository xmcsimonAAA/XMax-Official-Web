// jq 实现 返回顶部
function backToTop() {
  $("body,html").animate(
    {
      scrollTop: 0,
    },
    500
  );
}

const json = {
  Country: [
    "United States",
    "United Kingdom",
    "France",
    "Germany",
    "Italy",
    "Japan",
    "South Korea",
    "Taiwan, China",
    "Hong Kong, China",
    "Macao, China",
    "India",
    "Thailand",
    "Vietnam",
    "Malaysia",
    "Singapore",
    "Australia",
    "New Zealand",
    "Canada",
    "Brazil",
    "Argentina",
    "Russia",
    "Spain",
    "Mexico",
    "Sweden",
    "Denmark",
    "Norway",
    "Finland",
  ],
  Collaboration: [
    "Joint Feature Reporting",
    "In-Depth Content Partnership with Musk's Ecosystem",
    "Co-Produced Podcast or Video Documentary Series",
    "Media Event Sponsorship or Co-Hosting",
    "Others",
  ],
  Subject: ["investment", "marketing", "recruitment", "media"],
  Range: ["范围类型一", "范围类型二", "范围类型三"],
  Interest: ["SpaceX", "xAI", "Neuralink", "Comprehensive investments"],
};

$(function () {
  $(".opthions").each(function () {
    const type = $(this).attr("type");
    // console.log(json[type]);
    $(this).html(
      json[type]
        .map((item) => {
          return `<li>${item}</li>`;
        })
        .join("")
    );
  });
  $(".input-box .select").click(function () {
    $(this).parents(".is-select").toggleClass("open");
    $(".mask").toggleClass("show");
  });
  $(".mask").click(function () {
    $(this).removeClass("show");
    $(".is-select").removeClass("open");
  });
  $(".opthions li").click(function () {
    const $input = $(this).parents(".input-box").find("input");
    const isMultiple = $input.attr("multiple") !== undefined; // 检查multiple属性

    if (isMultiple) {
      $(this).toggleClass("active");
    } else {
      $(this).addClass("active").siblings().removeClass("active");
    }

    const selectedItems = $(this)
      .parent()
      .find(".active")
      .map(function () {
        return $(this).text();
      })
      .get()
      .join(isMultiple ? ", " : "");

    $input.val(selectedItems);
    if (!isMultiple) {
      $(this).parents(".is-select").removeClass("open");
      $(".mask").removeClass("show");
    }
  });
});

let option1 = {
  tooltip: {
    show: true,
    trigger: "axis",
    backgroundColor: "rgba(0,0,0,0.7)",
    borderColor: "#ffffff",
    borderWidth: 1,
    textStyle: {
      color: "#ffffff",
      fontSize: 14,
      align: "left", // 设置文本居左
      lineHeight: 24, // 设置行高为1.65
    },
    formatter: function (params) {
      return `${params[0].name}: ${params[0].value} Million Dollars<br/>`;
    },
    axisPointer: {
      type: "line",
      lineStyle: {
        color: "rgba(255,255,255,.15)",
        width: 1,
      },
    },
  },
  grid: {
    left: "20px",
    right: "0%",
    bottom: "2%",
    top: "10%",
    containLabel: true,
  },
  xAxis: [
    {
      type: "category",
      data: ["SpaceX", "xAI", "Neuralink"],
      axisTick: {
        alignWithLabel: true,
      },
      axisLine: {
        symbol: ["none", "arrow"],
        lineStyle: {
          color: "#ffffff",
        },
      },
      axisLabel: {
        fontSize: 15,
        color: "#fff",
        margin: 10,
      },
    },
  ],
  yAxis: [
    {
      type: "value",
      max: 33,
      axisLine: {
        symbol: ["none", "arrow"],
        show: true,
        lineStyle: {
          color: "#fff",
        },
      },
      axisLabel: {
        show: true, // 隐藏Y轴刻度
        fontSize: "12px",
        color: "#fff",
        formatter: function (value, index) {
          return value + "M"; // 显示Y轴刻度值，并在其后添加"M"
        },
      },
      splitLine: {
        show: false,
      },
    },
  ],
  series: [
    {
      name: "Capital",
      type: "bar",
      barWidth: "60px",
      itemStyle: {
        color: "#ffffff",
      },
      emphasis: {
        itemStyle: {
          color: "rgba(255,255,255,.5)", // 鼠标悬停时的颜色（这里用了金色，可以根据需要修改）
        },
      },
      // label: {
      //   show: true,
      //   position: "top",
      //   color: "#ffffff",
      //   fontSize: 15,
      //   formatter: (params) => `${params.value} Million Dollars`,
      // },
      data: [30, 18, 12],
    },
  ],
};
let option2 = {
  tooltip: {
    show: true,
    trigger: "axis",
    backgroundColor: "rgba(0,0,0,0.7)",
    borderColor: "#ffffff",
    borderWidth: 1,
    textStyle: {
      color: "#ffffff",
      fontSize: 14,
      align: "left", // 设置文本居左
      lineHeight: 24, // 设置行高为1.65
    },
    formatter: function (params) {
      return `${params[0].name}<br/>
      ${params[0].seriesName}: ${params[0].value} Million Dollars<br/>
      ${params[1].seriesName}: ${params[1].value} Million Dollars`;
    },
    axisPointer: {
      type: "line",
      lineStyle: {
        color: "rgba(255,255,255,.15)",
        width: 1,
      },
    },
  },
  grid: {
    left: "20px",
    right: "0%",
    bottom: "2%",
    top: "10%",
    containLabel: true,
  },
  xAxis: [
    {
      type: "category",
      data: ["SpaceX", "xAI", "Neuralink"],
      axisTick: {
        alignWithLabel: true,
      },
      axisLine: {
        symbol: ["none", "arrow"],
        lineStyle: {
          color: "#ffffff",
        },
      },
      axisLabel: {
        fontSize: 15,
        color: "#fff",
        margin: 10,
      },
    },
  ],
  yAxis: [
    {
      type: "value",
      max: 38,
      axisLine: {
        symbol: ["none", "arrow"],
        show: true,
        lineStyle: {
          color: "#fff",
        },
      },
      axisLabel: {
        show: true, // 隐藏Y轴刻度
        fontSize: "12px",
        color: "#fff",
        formatter: function (value, index) {
          return value + "M"; // 显示Y轴刻度值，并在其后添加"M"
        },
      },
      splitLine: {
        show: false,
      },
    },
  ],
  series: [
    {
      name: "Before Growth",
      type: "bar",
      barWidth: "45px",
      itemStyle: {
        color: "rgba(255,255,255,.65)",
      },
      // label: {
      //   show: true,
      //   position: "top",
      //   color: "#ffffff",
      //   fontSize: 15,
      //   formatter: (params) => `${params.value} Million Dollars`,
      // },
      data: [30, 18, 12],
    },
    {
      name: "After Growth",
      type: "bar",
      barWidth: "45px",
      itemStyle: {
        color: "#ffffff",
      },
      // label: {
      //   show: true,
      //   position: "top",
      //   color: "#ffffff",
      //   fontSize: 15,
      //   formatter: (params) => `${params.value} Million Dollars`,
      // },
      data: [35, 22, 18],
    },
  ],
};

// let option3 = {
//   tooltip: {
//     show: true,
//     trigger: "axis",
//     backgroundColor: "rgba(0,0,0,0.7)",
//     borderColor: "#ffffff",
//     borderWidth: 1,
//     textStyle: {
//       color: "#ffffff",
//       fontSize: 14,
//     },
//     formatter: function (params) {
//       // return `Value: ${params[0].value} Million<br/>
//       //         Year: ${params[0].name}`;
//       return `Valuation:USD 360B-Date:07/28<br/>Financing Amount: USD 4B<br/>Investors: Goldman Sachs, Xmax`;
//     },
//     axisPointer: {
//       type: "line",
//       lineStyle: {
//         color: "rgba(255,255,255,.15)",
//         width: 1,
//       },
//     },
//   },
//   grid: {
//     left: "0%",
//     right: "0%",
//     bottom: "2%",
//     top: "10%",
//     containLabel: true,
//   },
//   xAxis: [
//     // {
//     //   data: [
//     //     "2019-10-01",
//     //     "2019-10-02",
//     //     "2019-10-03",
//     //     "2019-10-04",
//     //     "2020-02-01",
//     //     "2022-05-01",
//     //     "2022-12-01",
//     //     "2023-05-01",
//     //     "2024-08-01",
//     //     "2025-07-01",
//     //   ],
//     //   axisTick: {
//     //     alignWithLabel: true,
//     //   },
//     //   axisLine: {
//     //     symbol: ["none", "arrow"],
//     //     lineStyle: {
//     //       color: "#ffffff",
//     //     },
//     //   },
//     //   axisLabel: {
//     //     fontSize: 15,
//     //     color: "#fff",
//     //     margin: 10,
//     //   },
//     // },
//     {
//       type: "value", // 数值轴（基于时间戳，自动处理间隔）
//       name: "时间",
//       // 自定义X轴标签：显示 年-月（间隔过近时自动隐藏避免重叠）
//       axisLabel: {
//         formatter: function (value) {
//           const date = new Date(value);
//           return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(
//             2,
//             "0"
//           )}`;
//         },
//         rotate: 45, // 标签旋转避免重叠
//         interval: "auto", // 自动调整标签间隔
//       },
//       // 网格线：帮助观察时间间隔
//       splitLine: {
//         show: true,
//         lineStyle: { type: "dashed" },
//       },
//     },
//   ],
//   yAxis: [
//     {
//       type: "value",
//       axisLine: {
//         symbol: ["none", "arrow"],
//         show: true,
//         lineStyle: {
//           color: "#fff",
//         },
//       },
//       axisLabel: {
//         fontSize: 0,
//       },
//       splitLine: {
//         show: false,
//       },
//     },
//   ],
//   series: [
//     {
//       data: processedData,
//       type: "line",
//       symbol: "circle", // 显示数据点标记
//       symbolSize: 8, // 标记点大小
//       lineStyle: { width: 2 }, // 折线粗细
//       // 强调数据点（可选）
//       emphasis: {
//         scale: true, // 鼠标hover时标记点放大
//       },
//     },
//   ],
// };
