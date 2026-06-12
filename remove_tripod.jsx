// PS 2026 兼容版：批量处理底部5%，调用动作填充
// 前提：已录制好名为“底部填充”的动作
try {
    var docCount = app.documents.length;
    var success = 0;

    for (var i = 0; i < docCount; i++) {
        app.activeDocument = app.documents[i];
        var doc = app.activeDocument;

        // 合并图层，确保可编辑
        if (doc.layers.length > 1) {
            doc.flatten();
        }

        // 计算底部5%选区
        var w = doc.width.as("px");
        var h = doc.height.as("px");
        var topY = h * 0.90; // 底部5%

        var selection = [
            [0, topY],
            [w, topY],
            [w, h],
            [0, h]
        ];
        doc.selection.select(selection);

        // 调用你录制好的动作
        app.doAction("底部填充", "默认动作");

        doc.selection.deselect();

        // 保存并关闭
        doc.save();
        doc.close(SaveOptions.DONOTSAVECHANGES);
        success++;
    }

    alert("✅ 处理完成！成功处理 " + success + " 张图片");
} catch (e) {
    alert("❌ 处理失败：" + e.message);
    try { app.activeDocument.selection.deselect(); } catch (e2) {}
}